<?php
// classes/ContactMessage.php
// Model for `enr_contact_messages`

require_once __DIR__ . '/Database.php';

class ContactMessage
{
    private Database $db;

    private const FILTERS = ['all', 'unread', 'read'];

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /* ---------------------------------------------------------
     *  CREATE
     * --------------------------------------------------------- */
    public function create(string $name, string $email, string $subject, string $message): int|false
    {
        $name    = trim($name);
        $email   = trim($email);
        $subject = trim($subject);
        $message = trim($message);

        if ($name === '' || $email === '' || $subject === '' || $message === '') {
            return false;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        try {
            $stmt = $this->db->prepare(
                "INSERT INTO enr_contact_messages
                    (name, email, subject, message, is_read, created_at)
                 VALUES (:name, :email, :subject, :message, 0, NOW())"
            );
            $ok = $stmt->execute([
                ':name'    => $name,
                ':email'   => $email,
                ':subject' => $subject,
                ':message' => $message,
            ]);
            return $ok ? (int) $this->db->lastInsertId() : false;
        } catch (Exception $e) {
            error_log('ContactMessage::create - ' . $e->getMessage());
            return false;
        }
    }

    /* ---------------------------------------------------------
     *  READ
     * --------------------------------------------------------- */
    public function getById(int $id): ?array
    {
        if ($id <= 0) return null;

        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM enr_contact_messages WHERE id = :id LIMIT 1"
            );
            $stmt->execute([':id' => $id]);
            return $stmt->fetch() ?: null;
        } catch (Exception $e) {
            error_log('ContactMessage::getById - ' . $e->getMessage());
            return null;
        }
    }

    public function getAll(array $opts = []): array
    {
        $filter = in_array($opts['filter'] ?? 'all', self::FILTERS, true)
            ? $opts['filter'] : 'all';
        $search = trim((string) ($opts['search'] ?? ''));
        $limit  = max(1, (int) ($opts['limit']  ?? 200));
        $offset = max(0, (int) ($opts['offset'] ?? 0));

        [$where, $params] = $this->buildWhere($filter, $search);

        $sql = "SELECT * FROM enr_contact_messages";
        if ($where) $sql .= " WHERE " . implode(' AND ', $where);
        $sql .= " ORDER BY created_at DESC, id DESC LIMIT {$limit} OFFSET {$offset}";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll() ?: [];
        } catch (Exception $e) {
            error_log('ContactMessage::getAll - ' . $e->getMessage());
            return [];
        }
    }

    public function count(string $filter = 'all', string $search = ''): int
    {
        if (!in_array($filter, self::FILTERS, true)) $filter = 'all';

        [$where, $params] = $this->buildWhere($filter, trim($search));

        $sql = "SELECT COUNT(*) AS c FROM enr_contact_messages";
        if ($where) $sql .= " WHERE " . implode(' AND ', $where);

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return (int) ($stmt->fetch()['c'] ?? 0);
        } catch (Exception $e) {
            error_log('ContactMessage::count - ' . $e->getMessage());
            return 0;
        }
    }

    public function countUnread(): int
    {
        return $this->count('unread');
    }

    /* ---------------------------------------------------------
     *  UPDATE
     * --------------------------------------------------------- */
    public function markRead(int $id, bool $read = true): bool
    {
        if ($id <= 0) return false;

        try {
            $stmt = $this->db->prepare(
                "UPDATE enr_contact_messages SET is_read = :r WHERE id = :id"
            );
            return $stmt->execute([':r' => $read ? 1 : 0, ':id' => $id]);
        } catch (Exception $e) {
            error_log('ContactMessage::markRead - ' . $e->getMessage());
            return false;
        }
    }

    public function markAllRead(): int
    {
        try {
            $stmt = $this->db->prepare(
                "UPDATE enr_contact_messages SET is_read = 1 WHERE is_read = 0"
            );
            $stmt->execute();
            return (int) $stmt->rowCount();
        } catch (Exception $e) {
            error_log('ContactMessage::markAllRead - ' . $e->getMessage());
            return 0;
        }
    }

    /* ---------------------------------------------------------
     *  DELETE
     * --------------------------------------------------------- */
    public function delete(int $id): bool
    {
        if ($id <= 0) return false;

        try {
            // Also delete replies for this message
            $del = $this->db->prepare("DELETE FROM enr_contact_replies WHERE message_id = :id");
            $del->execute([':id' => $id]);

            $stmt = $this->db->prepare(
                "DELETE FROM enr_contact_messages WHERE id = :id"
            );
            return $stmt->execute([':id' => $id]);
        } catch (Exception $e) {
            error_log('ContactMessage::delete - ' . $e->getMessage());
            return false;
        }
    }

    public function deleteMany(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            fn($i) => $i > 0
        )));

        if (!$ids) return 0;

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        try {
            // Delete replies first
            $del = $this->db->prepare(
                "DELETE FROM enr_contact_replies WHERE message_id IN ($placeholders)"
            );
            $del->execute($ids);

            $stmt = $this->db->prepare(
                "DELETE FROM enr_contact_messages WHERE id IN ($placeholders)"
            );
            $stmt->execute($ids);
            return (int) $stmt->rowCount();
        } catch (Exception $e) {
            error_log('ContactMessage::deleteMany - ' . $e->getMessage());
            return 0;
        }
    }

    /* ---------------------------------------------------------
     *  REPLIES
     * --------------------------------------------------------- */
    public function logReply(
        int $messageId,
        string $to,
        string $subject,
        string $body,
        bool $sent,
        ?string $error = null
    ): bool {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO enr_contact_replies
                    (message_id, sent_to, subject, body, status, error, created_at)
                 VALUES (:mid, :to, :sub, :body, :status, :err, NOW())"
            );
            return $stmt->execute([
                ':mid'    => $messageId,
                ':to'     => $to,
                ':sub'    => $subject,
                ':body'   => $body,
                ':status' => $sent ? 'sent' : 'failed',
                ':err'    => $error,
            ]);
        } catch (Exception $e) {
            error_log('ContactMessage::logReply - ' . $e->getMessage());
            return false;
        }
    }

    public function getReplies(int $messageId): array
    {
        if ($messageId <= 0) return [];

        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM enr_contact_replies
                 WHERE message_id = :mid
                 ORDER BY created_at ASC"
            );
            $stmt->execute([':mid' => $messageId]);
            return $stmt->fetchAll() ?: [];
        } catch (Exception $e) {
            error_log('ContactMessage::getReplies - ' . $e->getMessage());
            return [];
        }
    }

    /* ---------------------------------------------------------
     *  INTERNAL
     * --------------------------------------------------------- */
    private function buildWhere(string $filter, string $search): array
    {
        $where  = [];
        $params = [];

        if ($filter === 'unread')      $where[] = 'is_read = 0';
        elseif ($filter === 'read')    $where[] = 'is_read = 1';

        if ($search !== '') {
            $where[] = "(name LIKE :s OR email LIKE :s OR subject LIKE :s OR message LIKE :s)";
            $params[':s'] = '%' . $search . '%';
        }

        return [$where, $params];
    }
}
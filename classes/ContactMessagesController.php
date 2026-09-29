<?php
// classes/ContactMessagesController.php

require_once __DIR__ . '/ContactMessage.php';
require_once __DIR__ . '/Mailer.php';

class ContactMessagesController
{
    private ContactMessage $model;
    private array $data  = [];
    private ?array $flash = null;

    public function __construct(?ContactMessage $model = null)
    {
        $this->model = $model ?? new ContactMessage();
    }

    /* ---------------------------------------------------------
     *  ENTRY POINT
     * --------------------------------------------------------- */
    public function handle(): self
    {
        if ($this->isPost()) {
            $this->handlePost();   // redirects + exits
        }
        $this->handleGet();
        return $this;
    }

    /* ---------------------------------------------------------
     *  VIEW ACCESSORS
     * --------------------------------------------------------- */
    public function messages(): array       { return $this->data['messages'] ?? []; }
    public function activeMessage(): ?array { return $this->data['active']   ?? null; }
    public function filter(): string        { return $this->data['filter']   ?? 'all'; }
    public function search(): string        { return $this->data['search']   ?? ''; }
    public function counts(): array         { return $this->data['counts']   ?? ['all'=>0,'unread'=>0,'read'=>0]; }
    public function unreadCount(): int      { return (int) ($this->data['counts']['unread'] ?? 0); }
    public function flash(): ?array         { return $this->flash; }
    public function replies(): array        { return $this->data['replies'] ?? []; }

    /* ---------------------------------------------------------
     *  URL HELPERS (used in the template)
     * --------------------------------------------------------- */
    public function urlTab(string $filter): string
    {
        return '?' . $this->currentQuery(['filter' => $filter, 'view' => null]);
    }

    public function urlView(int $id): string
    {
        return '?' . $this->currentQuery(['view' => $id]);
    }

    public function urlClear(): string
    {
        return '?page=contact-messages';
    }

    public function currentQuery(array $override = []): string
    {
        $base = [
            'page'   => 'contact-messages',
            'filter' => $this->filter(),
            'q'      => $this->search() !== '' ? $this->search() : null,
            'view'   => null,
        ];
        $qs = array_filter(array_merge($base, $override), fn($v) => $v !== null && $v !== '');
        return http_build_query($qs);
    }

    /* ---------------------------------------------------------
     *  POST
     * --------------------------------------------------------- */
    private function handlePost(): void
    {
        $action = $_POST['action'] ?? '';
        $id     = (int) ($_POST['id'] ?? 0);

        switch ($action) {
            case 'mark_read':
                $this->model->markRead($id, true);
                $this->flash = ['type' => 'success', 'text' => 'Message marked as read.'];
                break;

            case 'mark_unread':
                $this->model->markRead($id, false);
                $this->flash = ['type' => 'info', 'text' => 'Message marked as unread.'];
                break;

            case 'mark_all_read':
                $this->model->markAllRead();
                $this->flash = ['type' => 'success', 'text' => 'All messages marked as read.'];
                break;

            case 'delete':
                $this->model->delete($id);
                $this->flash = ['type' => 'warning', 'text' => 'Message deleted.'];
                break;

            case 'bulk_delete':
                $n = $this->model->deleteMany((array) ($_POST['ids'] ?? []));
                $this->flash = ['type' => 'warning', 'text' => "{$n} message(s) deleted."];
                break;

            case 'reply':
                $this->handleReply($id);
                break;

            default:
                $this->flash = ['type' => 'info', 'text' => 'Unknown action.'];
        }

        $this->redirectAfterPost();
    }

    private function handleReply(int $id): void
    {
        $toEmail = trim((string) ($_POST['to_email'] ?? ''));
        $toName  = trim((string) ($_POST['to_name']  ?? ''));
        $subject = trim((string) ($_POST['reply_subject'] ?? ''));
        $body    = trim((string) ($_POST['reply_body'] ?? ''));

        if ($id <= 0) {
            $this->flash = ['type' => 'warning', 'text' => 'Invalid message ID.'];
            return;
        }

        // Verify the message actually exists
        $message = $this->model->getById($id);
        if (!$message) {
            $this->flash = ['type' => 'warning', 'text' => 'Message not found.'];
            return;
        }

        // Prefer the DB email/name to prevent tampering
        $toEmail = $message['email'] ?: $toEmail;
        $toName  = $message['name']  ?: $toName;

        // Send
        $mailer = new Mailer();
        $result = $mailer->sendReply($toEmail, $toName, $subject, $body);

        // Log the reply attempt
        $this->model->logReply(
            $id,
            $toEmail,
            $subject,
            $body,
            !empty($result['success']),
            $result['success'] ? null : ($result['message'] ?? 'Unknown error')
        );

        if (!empty($result['success'])) {
            $this->flash = ['type' => 'success', 'text' => 'Reply sent to ' . $toEmail];
        } else {
            $this->flash = [
                'type' => 'warning',
                'text' => 'Failed: ' . ($result['message'] ?? 'Unknown error'),
            ];
        }
    }

    private function redirectAfterPost(): void
    {
        $qs = $_SERVER['QUERY_STRING'] ?? '';
        $qs = preg_replace('/(?:^|&)flash=[^&]*/', '', $qs);
        $qs = ltrim((string) $qs, '&');
        $type = $this->flash['type'] ?? '';

        $target = '?' . ($qs !== '' ? $qs . '&' : '') . 'flash=' . urlencode($type);
        header('Location: ' . $target);
        exit;
    }

    /* ---------------------------------------------------------
     *  GET
     * --------------------------------------------------------- */
    private function handleGet(): void
    {
        $filter = $_GET['filter'] ?? 'all';
        if (!in_array($filter, ['all', 'unread', 'read'], true)) $filter = 'all';

        $search = trim((string) ($_GET['q'] ?? ''));
        $viewId = isset($_GET['view']) ? (int) $_GET['view'] : 0;

        $messages = $this->model->getAll([
            'filter' => $filter,
            'search' => $search,
            'limit'  => 200,
        ]);

        $active = $viewId ? $this->model->getById($viewId) : null;

        if ($active && empty($active['is_read'])) {
            $this->model->markRead((int) $active['id'], true);
            $active['is_read'] = 1;
        }

        $this->flash = $this->flash ?? $this->readFlashFromQuery();

        $this->data = [
            'filter'   => $filter,
            'search'   => $search,
            'messages' => is_array($messages) ? $messages : [],
            'active'   => $active ?: null,
            'replies'  => $active ? $this->model->getReplies((int) $active['id']) : [],
            'counts'   => [
                'all'    => $this->model->count('all'),
                'unread' => $this->model->countUnread(),
                'read'   => $this->model->count('read'),
            ],
        ];
    }

    private function readFlashFromQuery(): ?array
    {
        $type = $_GET['flash'] ?? '';
        if ($type === '') return null;

        $map = [
            'success' => 'Action completed successfully.',
            'info'    => 'Done.',
            'warning' => 'Action performed.',
        ];

        return isset($map[$type]) ? ['type' => $type, 'text' => $map[$type]] : null;
    }

    private function isPost(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }
}
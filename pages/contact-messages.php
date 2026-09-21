<?php
// pages/contact-messages.php — thin view

require_once __DIR__ . '/../classes/ContactMessagesController.php';

$ctrl = (new ContactMessagesController())->handle();

$messages      = $ctrl->messages();
$activeMessage = $ctrl->activeMessage();
$filter        = $ctrl->filter();
$search        = $ctrl->search();
$counts        = $ctrl->counts();
$unreadCount   = $ctrl->unreadCount();
$flash         = $ctrl->flash();

function h($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function timeAgo(string $ts): string {
    $diff = time() - strtotime($ts);
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return floor($diff / 60) . 'm ago';
    if ($diff < 86400)  return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M d, Y', strtotime($ts));
}

$currentDateTime = date('l, F d, Y h:i A');
$pageTitle       = 'Contact Messages';
?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<?php include __DIR__ . '/../includes/sidebar.php'; ?>

<main class="main-content">
    <div class="container">

        <div class="module-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:15px;">
            <div>
                <h1>✉️ Contact Messages</h1>
                <p class="dashboard-subtitle">
                    Inbox for visitor inquiries • <?php echo h($currentDateTime); ?>
                    <?php if ($unreadCount > 0): ?>
                        • <strong style="color:#b91c1c;"><?php echo $unreadCount; ?> unread</strong>
                    <?php endif; ?>
                </p>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <?php if ($unreadCount > 0): ?>
                <form method="post" style="display:inline;" onsubmit="return confirm('Mark all as read?');">
                    <input type="hidden" name="action" value="mark_all_read">
                    <button type="submit" class="btn btn-success" style="padding:10px 20px;font-weight:600;">
                        ✓ Mark All Read
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($flash)): ?>
            <div class="flash flash-<?php echo h($flash['type']); ?>">
                <?php echo h($flash['text']); ?>
            </div>
        <?php endif; ?>

        <div class="module-content">

            <!-- FILTER TABS -->
            <div class="filter-tabs">
                <?php
                $tabs = [
                    'all'    => ['label' => 'All',    'count' => $counts['all']],
                    'unread' => ['label' => 'Unread', 'count' => $counts['unread']],
                    'read'   => ['label' => 'Read',   'count' => $counts['read']],
                ];
                foreach ($tabs as $key => $tab):
                    $active = $filter === $key ? ' active' : '';
                ?>
                    <a class="filter-tab<?php echo $active; ?>" href="<?php echo h($ctrl->urlTab($key)); ?>">
                        <?php echo h($tab['label']); ?>
                        <span class="tab-count"><?php echo (int) $tab['count']; ?></span>
                    </a>
                <?php endforeach; ?>

                <form method="get" class="filter-search">
                    <input type="hidden" name="page" value="contact-messages">
                    <input type="hidden" name="filter" value="<?php echo h($filter); ?>">
                    <input type="text" name="q" value="<?php echo h($search); ?>"
                           placeholder="Search name, email, subject…">
                    <button type="submit" class="btn btn-primary">🔍</button>
                    <?php if ($search !== ''): ?>
                        <a href="<?php echo h($ctrl->urlClear()); ?>" class="btn btn-secondary">Clear</a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- INBOX -->
            <div class="inbox">

                <!-- LIST -->
                <div class="inbox-list">
                    <form method="post" id="bulkForm">
                        <input type="hidden" name="action" value="bulk_delete">

                        <div class="bulk-bar">
                            <label class="bulk-check">
                                <input type="checkbox" id="selectAll">
                                <span>Select all</span>
                            </label>
                            <button type="submit" class="btn btn-danger"
                                    onclick="return confirm('Delete selected messages?');">
                                🗑 Delete Selected
                            </button>
                        </div>

                        <?php if (empty($messages)): ?>
                            <div class="empty-state">
                                <div style="font-size:2.5rem;">📭</div>
                                <p>No messages <?php echo $filter !== 'all' ? 'in this filter' : 'yet'; ?>.</p>
                                <?php if ($search !== ''): ?>
                                    <p class="muted">No results for "<strong><?php echo h($search); ?></strong>".</p>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <?php foreach ($messages as $m): ?>
                                <?php
                                    $isActive = $activeMessage && (int) $activeMessage['id'] === (int) $m['id'];
                                    $rowCls   = 'msg-row' . ($m['is_read'] ? '' : ' unread') . ($isActive ? ' active' : '');
                                ?>
                                <div class="<?php echo $rowCls; ?>">
                                    <input type="checkbox" name="ids[]" value="<?php echo (int) $m['id']; ?>" class="row-check">
                                    <a class="msg-link" href="<?php echo h($ctrl->urlView((int) $m['id'])); ?>">
                                        <div class="msg-top">
                                            <span class="msg-name">
                                                <?php if (!$m['is_read']): ?><span class="dot"></span><?php endif; ?>
                                                <?php echo h($m['name']); ?>
                                            </span>
                                            <span class="msg-time"><?php echo h(timeAgo($m['created_at'])); ?></span>
                                        </div>
                                        <div class="msg-subject"><?php echo h($m['subject']); ?></div>
                                        <div class="msg-preview"><?php echo h(mb_strimwidth($m['message'], 0, 90, '…')); ?></div>
                                        <div class="msg-email"><?php echo h($m['email']); ?></div>
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </form>
                </div>

                <!-- READER -->
                <div class="inbox-reader">
                    <?php if ($activeMessage): ?>
                        <div class="reader-header">
                            <a href="?<?php echo h($ctrl->currentQuery()); ?>" class="btn btn-secondary">← Back</a>
                            <div class="reader-actions">
                                <?php if ($activeMessage['is_read']): ?>
                                    <form method="post" style="display:inline;">
                                        <input type="hidden" name="action" value="mark_unread">
                                        <input type="hidden" name="id" value="<?php echo (int) $activeMessage['id']; ?>">
                                        <button class="btn btn-info" type="submit">📩 Mark Unread</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" style="display:inline;">
                                        <input type="hidden" name="action" value="mark_read">
                                        <input type="hidden" name="id" value="<?php echo (int) $activeMessage['id']; ?>">
                                        <button class="btn btn-success" type="submit">✓ Mark Read</button>
                                    </form>
                                <?php endif; ?>

                                <form method="post" style="display:inline;"
                                      onsubmit="return confirm('Delete this message permanently?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo (int) $activeMessage['id']; ?>">
                                    <button class="btn btn-danger" type="submit">🗑 Delete</button>
                                </form>
                            </div>
                        </div>

                        <h2 class="reader-subject"><?php echo h($activeMessage['subject']); ?></h2>

                        <div class="reader-meta">
                            <div class="avatar"><?php echo h(mb_strtoupper(mb_substr($activeMessage['name'], 0, 1))); ?></div>
                            <div>
                                <div class="reader-from">
                                    <strong><?php echo h($activeMessage['name']); ?></strong>
                                    &lt;<a href="mailto:<?php echo h($activeMessage['email']); ?>"><?php echo h($activeMessage['email']); ?></a>&gt;
                                </div>
                                <div class="reader-date">
                                    <?php echo h(date('M d, Y h:i A', strtotime($activeMessage['created_at']))); ?>
                                    • <?php echo h(timeAgo($activeMessage['created_at'])); ?>
                                </div>
                            </div>
                        </div>

                        <div class="reader-body">
                            <?php echo nl2br(h($activeMessage['message'])); ?>
                        </div>

                        <div class="reader-footer">
                            <a class="btn btn-primary"
                               href="mailto:<?php echo h($activeMessage['email']); ?>?subject=<?php echo rawurlencode('Re: ' . $activeMessage['subject']); ?>">
                                ↩ Reply via Email
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="empty-state tall">
                            <div style="font-size:3rem;">📬</div>
                            <p>Select a message to read it here.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- QUICK ACTIONS -->
            <div class="quick-actions">
                <h2>⚡ Quick Actions</h2>
                <div class="action-grid">
                    <a href="<?php echo h($ctrl->urlTab('unread')); ?>" class="action-card">
                        <div class="action-icon">📩</div>
                        <div class="action-label">Unread (<?php echo (int) $counts['unread']; ?>)</div>
                    </a>
                    <a href="<?php echo h($ctrl->urlTab('read')); ?>" class="action-card">
                        <div class="action-icon">📖</div>
                        <div class="action-label">Read (<?php echo (int) $counts['read']; ?>)</div>
                    </a>
                    <a href="<?php echo h($ctrl->urlTab('all')); ?>" class="action-card">
                        <div class="action-icon">📥</div>
                        <div class="action-label">All (<?php echo (int) $counts['all']; ?>)</div>
                    </a>
                    <a href="?page=dashboard-overview" class="action-card">
                        <div class="action-icon">📊</div>
                        <div class="action-label">Dashboard</div>
                    </a>
                </div>
            </div>

        </div>
    </div>
</main>

<style>
    .dashboard-subtitle { color:#5a7fa8; font-size:14px; margin-bottom:0; }
    .muted { color:#94a3b8; font-size:13px; }

    .flash { margin:15px 0; padding:12px 18px; border-radius:8px; font-size:14px; font-weight:500; }
    .flash-success { background:#d4e8fc; color:#1a3c6e; border-left:4px solid #2a5c9e; }
    .flash-info    { background:#e0ecf8; color:#1a3c6e; border-left:4px solid #4a90d9; }
    .flash-warning { background:#e8f0fe; color:#1a3c6e; border-left:4px solid #6aa8e0; }

    .filter-tabs {
        display:flex; align-items:center; gap:8px; flex-wrap:wrap;
        background:white; border-radius:12px; padding:12px 16px;
        box-shadow:0 2px 8px rgba(26,60,110,0.08); margin-bottom:20px;
    }
    .filter-tab {
        display:inline-flex; align-items:center; gap:6px;
        padding:8px 14px; border-radius:20px;
        background:#f0f5fc; color:#1a3c6e; text-decoration:none;
        font-size:13px; font-weight:600; transition:all .2s;
    }
    .filter-tab:hover { background:#e0ecf8; }
    .filter-tab.active { background:#1a3c6e; color:white; }
    .tab-count {
        background:rgba(26,60,110,0.12); padding:1px 8px;
        border-radius:10px; font-size:11px; font-weight:700;
    }
    .filter-tab.active .tab-count { background:rgba(255,255,255,0.25); }
    .filter-search { margin-left:auto; display:flex; gap:6px; align-items:center; }
    .filter-search input[type=text] {
        padding:8px 12px; border:1px solid #d0e0f0; border-radius:6px;
        font-size:13px; min-width:220px; outline:none;
    }
    .filter-search input[type=text]:focus { border-color:#4a90d9; }

    .inbox { display:grid; grid-template-columns: 380px 1fr; gap:20px; min-height:520px; }
    .inbox-list, .inbox-reader {
        background:white; border-radius:12px;
        box-shadow:0 2px 8px rgba(26,60,110,0.08); overflow:hidden;
    }
    .inbox-list { padding:12px; }
    .inbox-reader { padding:24px; }

    .bulk-bar {
        display:flex; justify-content:space-between; align-items:center;
        padding:8px 4px; border-bottom:1px solid #e8f0fe; margin-bottom:6px;
    }
    .bulk-check { display:flex; align-items:center; gap:8px; font-size:13px; color:#5a7fa8; }

    .msg-row {
        display:flex; gap:8px; align-items:flex-start;
        padding:10px 6px; border-radius:8px;
        border-bottom:1px solid #f0f5fc; transition:background .15s;
    }
    .msg-row:hover { background:#f0f5fc; }
    .msg-row.active { background:#e0ecf8; }
    .msg-row.unread .msg-name { font-weight:800; color:#0f2a4e; }
    .msg-row.unread .msg-subject { color:#1a3c6e; }

    .row-check { margin-top:6px; }
    .msg-link { flex:1; text-decoration:none; color:inherit; display:block; min-width:0; }
    .msg-top { display:flex; justify-content:space-between; gap:8px; align-items:baseline; }
    .msg-name {
        font-weight:600; font-size:13.5px; color:#1a3c6e;
        overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
        display:flex; align-items:center; gap:6px; min-width:0;
    }
    .dot { width:8px; height:8px; background:#ef4444; border-radius:50%; display:inline-block; flex-shrink:0; }
    .msg-time { font-size:11px; color:#94a3b8; flex-shrink:0; }
    .msg-subject { font-size:13px; color:#2a5c9e; margin-top:3px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .msg-preview { font-size:12px; color:#64748b; margin-top:2px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .msg-email { font-size:11px; color:#94a3b8; margin-top:3px; }

    .reader-header { display:flex; justify-content:space-between; gap:10px; margin-bottom:20px; }
    .reader-actions { display:flex; gap:8px; flex-wrap:wrap; }
    .reader-subject {
        color:#0f2a4e; font-size:20px; margin:0 0 16px 0;
        padding-bottom:14px; border-bottom:1px solid #e8f0fe;
    }
    .reader-meta { display:flex; gap:12px; align-items:center; margin-bottom:18px; }
    .avatar {
        width:44px; height:44px; border-radius:50%;
        background:linear-gradient(135deg,#1a3c6e,#4a90d9);
        color:white; font-weight:700; font-size:18px;
        display:flex; align-items:center; justify-content:center; flex-shrink:0;
    }
    .reader-from { font-size:14px; color:#1a3c6e; }
    .reader-from a { color:#2a5c9e; text-decoration:none; }
    .reader-from a:hover { text-decoration:underline; }
    .reader-date { font-size:12px; color:#94a3b8; margin-top:2px; }
    .reader-body {
        background:#f8fbff; border-radius:10px; padding:20px;
        font-size:14px; line-height:1.7; color:#1e293b;
        white-space:pre-wrap; word-wrap:break-word; min-height:180px;
    }
    .reader-footer { margin-top:20px; display:flex; gap:10px; }

    .empty-state { text-align:center; padding:40px 20px; color:#5a7fa8; }
    .empty-state.tall { padding:120px 20px; }
    .empty-state p { margin:8px 0 0 0; font-size:14px; }

    .btn {
        padding:8px 16px; border:none; border-radius:6px; cursor:pointer;
        text-decoration:none; display:inline-block; transition:all .2s ease;
        font-size:13px; font-weight:600;
    }
    .btn-primary   { background:#1a3c6e; color:white; }
    .btn-primary:hover { background:#2a5c9e; }
    .btn-success   { background:#2a5c9e; color:white; }
    .btn-success:hover { background:#1a3c6e; }
    .btn-info      { background:#4a90d9; color:white; }
    .btn-info:hover { background:#3a7bc8; }
    .btn-secondary { background:#5a7fa8; color:white; }
    .btn-secondary:hover { background:#4a6a8a; }
    .btn-danger    { background:#0f2a4e; color:white; }
    .btn-danger:hover { background:#1a3c6e; }

    .quick-actions {
        background:white; border-radius:12px; padding:20px;
        margin-top:20px; box-shadow:0 2px 8px rgba(26,60,110,0.08);
    }
    .quick-actions h2 { margin-bottom:15px; color:#1a3c6e; font-size:18px; }
    .action-grid {
        display:grid; grid-template-columns:repeat(auto-fit, minmax(150px,1fr));
        gap:15px; margin-top:10px;
    }
    .action-card {
        display:flex; flex-direction:column; align-items:center;
        padding:20px; background:#f0f5fc; border-radius:10px;
        text-decoration:none; color:#1a3c6e;
        transition:all .3s ease; border:2px solid transparent;
    }
    .action-card:hover {
        background:#e0ecf8; border-color:#1a3c6e;
        transform:translateY(-4px); box-shadow:0 4px 12px rgba(26,60,110,0.15);
    }
    .action-icon { font-size:2rem; margin-bottom:8px; }
    .action-label { font-size:13px; font-weight:500; text-align:center; }

    @media (max-width: 992px) { .inbox { grid-template-columns: 1fr; } }
    @media (max-width: 768px) {
        .filter-search { margin-left:0; width:100%; }
        .filter-search input[type=text] { flex:1; min-width:0; }
        .reader-header { flex-direction:column; align-items:flex-start; }
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const selAll = document.getElementById('selectAll');
    if (selAll) {
        const boxes = () => document.querySelectorAll('.row-check');
        selAll.addEventListener('change', function () {
            boxes().forEach(b => b.checked = selAll.checked);
        });
        document.querySelectorAll('.row-check').forEach(b => {
            b.addEventListener('change', function () {
                const all = boxes();
                const checked = document.querySelectorAll('.row-check:checked');
                selAll.indeterminate = checked.length > 0 && checked.length < all.length;
                selAll.checked = checked.length === all.length && all.length > 0;
            });
        });
    }
    const q = new URLSearchParams(window.location.search).get('q');
    if (q) {
        const inp = document.querySelector('.filter-search input[name=q]');
        if (inp) inp.focus();
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
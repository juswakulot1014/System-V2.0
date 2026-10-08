<?php
// config/audit.php — lightweight audit log.
//
// Logs admin actions to the audit_log table.
//
// Usage:
//   require_once __DIR__ . '/audit.php';    // usually from bootstrap or auth
//   audit_log($conn, 'admin.login',    ['type' => 'admin',   'id' => 1]);
//   audit_log($conn, 'student.delete', ['type' => 'student', 'id' => 42]);
//   audit_log($conn, 'grade.edit',     ['type' => 'grade',   'id' => 7, 'old' => 88, 'new' => 92]);
//
//   audit_diff($conn, 'grade.update', $before, $after, 'grade', $grade_id);
//
// The function never throws. If the log write fails (missing table, DB down),
// it silently logs to error_log and returns — the page keeps running.
// Never let auditing break the actual request.

if (!function_exists('audit_log')) {

    function audit_log(mysqli $conn, string $action, array $meta = []): void
    {
        static $stmt = null;

        if ($stmt === null) {
            $stmt = $conn->prepare("
                INSERT INTO audit_log
                    (admin_id, action, target_type, target_id,
                     ip_address, user_agent, meta, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            if (!$stmt) {
                error_log('audit_log: prepare failed: ' . $conn->error);
                return;
            }
        }

        $admin_id    = (int)($_SESSION['admin_id'] ?? 0);
        $target_type = isset($meta['type']) ? (string)$meta['type'] : null;
        $target_id   = isset($meta['id'])   ? (int)$meta['id']      : null;
        $ip          = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua          = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
        $json        = !empty($meta)
            ? json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : null;

        // mysqli bind_param: types string is 7 chars for 7 placeholders.
        // admin_id(i), action(s), target_type(s), target_id(i),
        // ip_address(s), user_agent(s), meta(s)
        $stmt->bind_param(
            'ississs',
            $admin_id,
            $action,
            $target_type,
            $target_id,
            $ip,
            $ua,
            $json
        );

        if (!$stmt->execute()) {
            error_log('audit_log: execute failed: ' . $stmt->error);
        }
        // Do NOT close the statement — it is reused across the request via `static`.
    }
}

if (!function_exists('audit_diff')) {

    /**
     * Log a field-level diff between two states of the same record.
     *
     * Only fields whose values actually changed are recorded. If nothing
     * changed, the function is a no-op — no empty row is written.
     *
     * The resulting audit_log row has:
     *   action      = $action
     *   target_type = $entity_type
     *   target_id   = $entity_id
     *   meta        = {
     *                   "type":    "<entity_type>",
     *                   "id":      <entity_id>,
     *                   "changes": { "field": {"from": X, "to": Y}, ... }
     *                 }
     *
     * @param mysqli $conn
     * @param string $action      Dotted action name, e.g. 'grade.update'
     * @param array  $before      Old column => value map
     * @param array  $after       New column => value map
     * @param string $entity_type e.g. 'grade', 'education'
     * @param int    $entity_id   Primary key of the affected row
     */
    function audit_diff(
        mysqli $conn,
        string $action,
        array  $before,
        array  $after,
        string $entity_type,
        int    $entity_id
    ): void {
        $keys    = array_unique(array_merge(array_keys($before), array_keys($after)));
        $changes = [];

        foreach ($keys as $field) {
            $old = $before[$field] ?? null;
            $new = $after[$field]  ?? null;

            // Numeric-aware compare so 75 vs "75.00" doesn't create false diffs.
            if (is_numeric($old) && is_numeric($new)) {
                if ((float)$old === (float)$new) continue;
            } elseif ((string)$old === (string)$new) {
                continue;
            }

            $changes[$field] = [
                'from' => $old,
                'to'   => $new,
            ];
        }

        // Nothing actually changed → don't pollute the audit trail.
        if (empty($changes)) {
            return;
        }

        audit_log($conn, $action, [
            'type'    => $entity_type,
            'id'      => $entity_id,
            'changes' => $changes,
        ]);
    }
}
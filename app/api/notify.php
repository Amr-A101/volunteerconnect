<?php
    function createNotification(array $data) {
        global $dbc;

        $stmt = $dbc->prepare("
            INSERT INTO notifications
            (user_id, role_target, title, message, type,
            action_url, context_type, context_id,
            is_dismissible, created_by, created_by_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->bind_param(
            "issssssiisi",
            $data['user_id'],
            $data['role_target'],
            $data['title'],
            $data['message'],
            $data['type'],
            $data['action_url'],
            $data['context_type'],
            $data['context_id'],
            $data['is_dismissible'],
            $data['created_by'],
            $data['created_by_id']
        );

        $stmt->execute();
        $stmt->close();
    }

    function notifyUsers(array $userIds, array $payload): void
    {
        foreach (array_unique($userIds) as $uid) {
            createNotification([
                'user_id' => $uid,
                'role_target' => null,
                'title' => $payload['title'],
                'message' => $payload['message'],
                'type' => $payload['type'] ?? 'system',
                'action_url' => $payload['action_url'] ?? null,
                'context_type' => $payload['context_type'] ?? null,
                'context_id' => $payload['context_id'] ?? null,
                'is_dismissible' => 1,
                'created_by' => 'system',
                'created_by_id' => null
            ]);
        }
    }

    function broadcastAnnouncement(array $payload): void
    {
        global $dbc;

        // Determine target roles
        $role = $payload['role_target']; // all | volunteer | organization

        $sql = "SELECT user_id FROM users WHERE status = 'verified'";

        if ($role === 'volunteer') {
            $sql .= " AND role = 'vol'";
        } elseif ($role === 'organization') {
            $sql .= " AND role = 'org'";
        }

        $res = $dbc->query($sql);

        if (!$res) return;

        while ($row = $res->fetch_assoc()) {
            createNotification([
                'user_id' => (int)$row['user_id'],
                'role_target' => $role,
                'title' => $payload['title'],
                'message' => $payload['message'],
                'type' => $payload['type'] ?? 'system',
                'action_url' => $payload['action_url'] ?? null,
                'context_type' => 'announcement',
                'context_id' => null,
                'is_dismissible' => 1,
                'created_by' => 'admin',
                'created_by_id' => $payload['created_by_id']
            ]);
        }
    }

?>
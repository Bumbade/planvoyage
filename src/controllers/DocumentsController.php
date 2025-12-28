<?php

// src/controllers/DocumentsController.php
require_once __DIR__ . '/../config/mysql.php';

class DocumentsController
{
    protected $db;

    public function __construct()
    {
        $this->db = get_db();
    }

    // GET /api/documents?q=term&page=1
    public function apiSearch()
    {
        $q = trim($_GET['q'] ?? '');
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        if ($q === '') {
            $res = [ 'data' => [], 'page' => $page, 'per_page' => $perPage, 'total' => 0 ];
            header('Content-Type: application/json');
            echo json_encode($res);
            return;
        }
        // Prefer FULLTEXT MATCH, but fall back to a LIKE query if MATCH is not available
        try {
            $stmt = $this->db->prepare(
                "SELECT id, filename, title, mime, filesize, created_at, MATCH(content, filename, title) AGAINST (:q IN NATURAL LANGUAGE MODE) AS score
                 FROM documents
                 WHERE MATCH(content, filename, title) AGAINST (:q IN NATURAL LANGUAGE MODE)
                 ORDER BY score DESC
                 LIMIT :limit OFFSET :offset"
            );
            $stmt->bindValue(':q', $q);
            $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // total count
            $cntStmt = $this->db->prepare("SELECT COUNT(*) AS c FROM documents WHERE MATCH(content, filename, title) AGAINST (:q IN NATURAL LANGUAGE MODE)");
            $cntStmt->execute([':q' => $q]);
            $total = (int)$cntStmt->fetchColumn();
        } catch (PDOException $e) {
            // Fallback to LIKE-based search (slower) if fulltext isn't available
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $sql = "SELECT id, filename, title, mime, filesize, created_at FROM documents
                    WHERE content LIKE :like OR filename LIKE :like OR title LIKE :like
                    ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':like', $like, PDO::PARAM_STR);
            $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $cnt = $this->db->prepare("SELECT COUNT(*) AS c FROM documents WHERE content LIKE :like OR filename LIKE :like OR title LIKE :like");
            $cnt->execute([':like' => $like]);
            $total = (int)$cnt->fetchColumn();
        }

        $out = [ 'data' => $rows, 'page' => $page, 'per_page' => $perPage, 'total' => $total ];
        header('Content-Type: application/json');
        echo json_encode($out);
    }
}

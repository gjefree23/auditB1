<?php
/**
 * Checklist por bodega / Admin de plantillas
 * GET  ?action=for_warehouse&warehouse=
 * GET  ?action=list_templates
 * GET  ?action=get_template&id=
 * POST ?action=save_template   {id?, name, warehouse_code, warehouse_type, active, items:[{id?, sort_order, question, required}]}
 * POST ?action=toggle_template {id, active}
 * POST ?action=delete_template {id}
 */
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
handle_preflight();

$action = $_GET['action'] ?? 'for_warehouse';
$method = $_SERVER['REQUEST_METHOD'];

if ($action === 'for_warehouse' && $method === 'GET') {
    require_auth();
    $whs = trim((string)($_GET['warehouse'] ?? ''));
    $pdo = db();

    $tpl = null;
    if ($whs !== '') {
        $st = $pdo->prepare('SELECT * FROM checklist_templates WHERE active=1 AND warehouse_code = ? LIMIT 1');
        $st->execute([$whs]);
        $tpl = $st->fetch() ?: null;
    }
    if (!$tpl) {
        $tpl = $pdo->query(
            "SELECT * FROM checklist_templates WHERE active=1 AND (warehouse_code IS NULL OR warehouse_code='') ORDER BY id ASC LIMIT 1"
        )->fetch();
    }
    if (!$tpl) {
        json_response(['ok' => true, 'data' => ['template' => null, 'items' => []]]);
    }

    $items = $pdo->prepare(
        'SELECT id, sort_order, question, required FROM checklist_template_items WHERE template_id = ? ORDER BY sort_order, id'
    );
    $items->execute([$tpl['id']]);
    $list = $items->fetchAll();
    foreach ($list as &$it) {
        $it['id'] = (int)$it['id'];
        $it['required'] = (int)$it['required'];
        $it['answer'] = 'SI';
        $it['comment'] = '';
    }
    unset($it);
    json_response([
        'ok' => true,
        'data' => [
            'template' => [
                'id' => (int)$tpl['id'],
                'name' => $tpl['name'],
                'warehouse_type' => $tpl['warehouse_type'],
                'warehouse_code' => $tpl['warehouse_code'],
            ],
            'items' => $list,
        ],
    ]);
}

if ($action === 'list_templates' && $method === 'GET') {
    require_auth(['ADMIN', 'SUPERVISOR']);
    $rows = db()->query(
        "SELECT t.*,
                (SELECT COUNT(*) FROM checklist_template_items i WHERE i.template_id = t.id) AS item_count
         FROM checklist_templates t
         ORDER BY t.id"
    )->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['active'] = (int)$r['active'];
        $r['item_count'] = (int)$r['item_count'];
        if ($r['warehouse_code'] === null) {
            $r['warehouse_code'] = '';
        }
    }
    unset($r);
    json_response(['ok' => true, 'data' => $rows]);
}

if ($action === 'get_template' && $method === 'GET') {
    require_auth(['ADMIN', 'SUPERVISOR']);
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        json_error('ID inválido');
    }
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM checklist_templates WHERE id = ?');
    $st->execute([$id]);
    $tpl = $st->fetch();
    if (!$tpl) {
        json_error('Plantilla no encontrada', 404);
    }
    $tpl['id'] = (int)$tpl['id'];
    $tpl['active'] = (int)$tpl['active'];
    $tpl['warehouse_code'] = $tpl['warehouse_code'] ?? '';

    $items = $pdo->prepare(
        'SELECT id, sort_order, question, required FROM checklist_template_items WHERE template_id = ? ORDER BY sort_order, id'
    );
    $items->execute([$id]);
    $list = $items->fetchAll();
    foreach ($list as &$it) {
        $it['id'] = (int)$it['id'];
        $it['sort_order'] = (int)$it['sort_order'];
        $it['required'] = (int)$it['required'];
    }
    unset($it);

    json_response(['ok' => true, 'data' => ['template' => $tpl, 'items' => $list]]);
}

if ($action === 'save_template' && $method === 'POST') {
    require_admin();
    $b = read_json_body();
    $id = (int)($b['id'] ?? 0);
    $name = trim((string)($b['name'] ?? ''));
    $whsCode = trim((string)($b['warehouse_code'] ?? ''));
    $whsType = trim((string)($b['warehouse_type'] ?? 'GENERAL'));
    $active = !empty($b['active']) ? 1 : 0;
    $items = $b['items'] ?? [];

    if ($name === '') {
        json_error('El nombre de la plantilla es obligatorio');
    }
    if ($whsType === '') {
        $whsType = 'GENERAL';
    }
    if (!is_array($items) || count($items) === 0) {
        json_error('Agregue al menos una pregunta');
    }

    $cleanItems = [];
    $order = 1;
    foreach ($items as $it) {
        $q = trim((string)($it['question'] ?? ''));
        if ($q === '') {
            continue;
        }
        $cleanItems[] = [
            'id' => (int)($it['id'] ?? 0),
            'sort_order' => (int)($it['sort_order'] ?? $order),
            'question' => mb_substr($q, 0, 255),
            'required' => !empty($it['required']) ? 1 : 0,
        ];
        $order++;
    }
    if (!$cleanItems) {
        json_error('Agregue al menos una pregunta válida');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($id > 0) {
            $chk = $pdo->prepare('SELECT id FROM checklist_templates WHERE id = ?');
            $chk->execute([$id]);
            if (!$chk->fetch()) {
                throw new RuntimeException('Plantilla no encontrada');
            }
            $pdo->prepare(
                'UPDATE checklist_templates SET name=?, warehouse_code=?, warehouse_type=?, active=? WHERE id=?'
            )->execute([
                $name,
                $whsCode !== '' ? $whsCode : null,
                $whsType,
                $active,
                $id,
            ]);
        } else {
            $pdo->prepare(
                'INSERT INTO checklist_templates (name, warehouse_code, warehouse_type, active) VALUES (?,?,?,?)'
            )->execute([
                $name,
                $whsCode !== '' ? $whsCode : null,
                $whsType,
                $active,
            ]);
            $id = (int)$pdo->lastInsertId();
        }

        $keepIds = [];
        $ins = $pdo->prepare(
            'INSERT INTO checklist_template_items (template_id, sort_order, question, required) VALUES (?,?,?,?)'
        );
        $upd = $pdo->prepare(
            'UPDATE checklist_template_items SET sort_order=?, question=?, required=? WHERE id=? AND template_id=?'
        );

        foreach ($cleanItems as $idx => $it) {
            $sort = $it['sort_order'] > 0 ? $it['sort_order'] : ($idx + 1);
            if ($it['id'] > 0) {
                $upd->execute([$sort, $it['question'], $it['required'], $it['id'], $id]);
                if ($upd->rowCount() >= 0) {
                    $keepIds[] = $it['id'];
                }
            } else {
                $ins->execute([$id, $sort, $it['question'], $it['required']]);
                $keepIds[] = (int)$pdo->lastInsertId();
            }
        }

        if ($keepIds) {
            $in = implode(',', array_map('intval', $keepIds));
            $pdo->exec("DELETE FROM checklist_template_items WHERE template_id = {$id} AND id NOT IN ({$in})");
        } else {
            $pdo->prepare('DELETE FROM checklist_template_items WHERE template_id = ?')->execute([$id]);
        }

        $pdo->commit();
        json_response(['ok' => true, 'id' => $id, 'message' => 'Plantilla guardada']);
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_error('No se pudo guardar: ' . $e->getMessage(), 500);
    }
}

if ($action === 'toggle_template' && $method === 'POST') {
    require_admin();
    $b = read_json_body();
    $id = (int)($b['id'] ?? 0);
    $active = !empty($b['active']) ? 1 : 0;
    if ($id <= 0) {
        json_error('ID inválido');
    }
    $st = db()->prepare('UPDATE checklist_templates SET active = ? WHERE id = ?');
    $st->execute([$active, $id]);
    if ($st->rowCount() === 0) {
        json_error('Plantilla no encontrada', 404);
    }
    json_response(['ok' => true, 'message' => $active ? 'Plantilla activada' : 'Plantilla desactivada']);
}

if ($action === 'delete_template' && $method === 'POST') {
    require_admin();
    $b = read_json_body();
    $id = (int)($b['id'] ?? 0);
    if ($id <= 0) {
        json_error('ID inválido');
    }
    $st = db()->prepare('DELETE FROM checklist_templates WHERE id = ?');
    $st->execute([$id]);
    if ($st->rowCount() === 0) {
        json_error('Plantilla no encontrada', 404);
    }
    json_response(['ok' => true, 'message' => 'Plantilla eliminada']);
}

json_error('Acción no válida', 404);

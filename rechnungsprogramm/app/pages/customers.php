<?php
declare(strict_types=1);

const CUSTOMER_FIELDS = ['company', 'contact_person', 'firstname', 'lastname', 'street', 'zip', 'city', 'phone', 'email', 'notes'];

function customers_index(): void {
    $q = trim((string)($_GET['q'] ?? ''));
    $sql = 'SELECT c.*, (SELECT COUNT(*) FROM invoices i WHERE i.customer_id = c.id) AS inv_count FROM customers c';
    $p = [];
    if ($q !== '') {
        $sql .= ' WHERE company LIKE :q OR firstname LIKE :q OR lastname LIKE :q OR city LIKE :q OR email LIKE :q OR phone LIKE :q';
        $p[':q'] = '%' . $q . '%';
    }
    $st = db()->prepare($sql . ' ORDER BY COALESCE(NULLIF(company, \'\'), lastname) COLLATE NOCASE');
    $st->execute($p);
    render('customers', ['customers' => $st->fetchAll(), 'q' => $q], 'Kunden');
}

function customers_edit(): void {
    $id = (int)($_GET['id'] ?? 0);
    $c = array_fill_keys(CUSTOMER_FIELDS, ''); $c['id'] = 0;
    if ($id) {
        $st = db()->prepare('SELECT * FROM customers WHERE id = ?'); $st->execute([$id]);
        $c = $st->fetch() ?: redirect('customers');
    }
    $invoices = [];
    if ($id) {
        $st = db()->prepare('SELECT * FROM invoices WHERE customer_id = ? ORDER BY invoice_date DESC, id DESC'); $st->execute([$id]);
        $invoices = $st->fetchAll();
    }
    render('customer_form', ['c' => $c, 'invoices' => $invoices], $id ? 'Kunde bearbeiten' : 'Neuer Kunde');
}

function customers_save(): void {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $d = []; foreach (CUSTOMER_FIELDS as $f) $d[$f] = post($f);
    if ($d['company'] === '' && $d['lastname'] === '') {
        flash('Bitte Firma oder Nachname angeben.', 'err');
        $_SESSION['old'] = $d;
        redirect('customer_edit', $id ? ['id' => $id] : []);
    }
    if ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
        flash('Die E-Mail-Adresse ist ungültig.', 'err');
        redirect('customer_edit', $id ? ['id' => $id] : []);
    }
    if ($id) {
        $sets = implode(', ', array_map(fn($f) => "$f = :$f", CUSTOMER_FIELDS));
        db()->prepare("UPDATE customers SET $sets WHERE id = :id")->execute($d + ['id' => $id]);
    } else {
        $cols = implode(', ', CUSTOMER_FIELDS);
        $ph = implode(', ', array_map(fn($f) => ":$f", CUSTOMER_FIELDS));
        db()->prepare("INSERT INTO customers ($cols) VALUES ($ph)")->execute($d);
        $id = (int)db()->lastInsertId();
    }
    flash('Kunde gespeichert.');
    if (isset($_POST['then_invoice'])) redirect('invoice_new', ['customer_id' => $id]);
    redirect('customers');
}

function customers_delete(): void {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $st = db()->prepare('SELECT COUNT(*) FROM invoices WHERE customer_id = ?'); $st->execute([$id]);
    if ((int)$st->fetchColumn() > 0) {
        flash('Dieser Kunde hat Rechnungen und kann nicht gelöscht werden (gesetzliche Aufbewahrungspflicht).', 'err');
        redirect('customer_edit', ['id' => $id]);
    }
    db()->prepare('DELETE FROM customers WHERE id = ?')->execute([$id]);
    flash('Kunde gelöscht.');
    redirect('customers');
}

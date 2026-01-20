<?php
// Month detail view — pemasukan & pengeluaran terpisah
$dbFile = __DIR__ . '/data.db';
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// set server timezone agar waktu mengikuti jam yang sekarang
date_default_timezone_set('Asia/Jakarta');

function day_name_id($datetime) {
    $days = ['Sunday'=>'Minggu','Monday'=>'Senin','Tuesday'=>'Selasa','Wednesday'=>'Rabu','Thursday'=>'Kamis','Friday'=>'Jumat','Saturday'=>'Sabtu'];
    $d = date('l', strtotime($datetime));
    return $days[$d] ?? $d;
}

$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');

// Handle delete
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM transactions WHERE id = ?");
    $stmt->execute([$id]);
    header('Location: ?year=' . $year . '&month=' . $month);
    exit;
}

// Fetch transactions for the month
$stmt = $pdo->prepare("SELECT * FROM transactions WHERE month = ? AND year = ? ORDER BY dt DESC");
$stmt->execute([$month, $year]);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Split and totals
$ins = array_values(array_filter($transactions, function($t){ return $t['type'] === 'in'; }));
$outs = array_values(array_filter($transactions, function($t){ return $t['type'] === 'out'; }));
$total_in = array_sum(array_map(function($t){ return (int)$t['qty']; }, $ins));
$total_out = array_sum(array_map(function($t){ return (int)$t['qty']; }, $outs));
$months = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];

// current server timestamp for JS sync
$server_now_ts = time();

// EDIT: handle edit request
$editTransaction = null;
if (isset($_GET['edit'])) {
	$id = (int)$_GET['edit'];
	$stmt = $pdo->prepare("SELECT * FROM transactions WHERE id = ?");
	$stmt->execute([$id]);
	$editTransaction = $stmt->fetch(PDO::FETCH_ASSOC);
	if($editTransaction) {
		// ensure qty is always >= 1
		$editTransaction['qty'] = max(1, (int)$editTransaction['qty']);
	}
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Transaksi Bulan <?= htmlspecialchars($month) ?> - <?= htmlspecialchars($months[$month-1] ?? '') ?> <?= htmlspecialchars($year) ?></title>
<style>
body{font-family:Arial,Helvetica,sans-serif;max-width:1000px;margin:20px auto;padding:0 15px;color:#222}
table{width:100%;border-collapse:collapse;margin-top:10px}
th,td{padding:8px;border:1px solid #ddd;text-align:left}
.actions a{color:#06c;text-decoration:none;margin-right:6px}
.summary{display:flex;gap:12px;margin-bottom:10px}
.summary div{padding:8px;border:1px solid #ddd;background:#fafafa}
.section{margin-bottom:20px}
.server-clock{font-weight:600}
</style>
</head>
<body>
<h1>Transaksi Bulan <?= $month ?> - <?= $months[$month-1] ?? '' ?> <?= $year ?></h1>

<!-- Server clock (auto-update) -->
<div style="margin-bottom:12px">Waktu Server: <span id="server-clock" class="server-clock"></span></div>

<div class="summary">
    <div>Total Masuk: <?= $total_in ?></div>
    <div>Total Keluar: <?= $total_out ?></div>
    <div>Selisih: <?= $total_in - $total_out ?></div>
</div>

<div class="section">
    <h2>Pemasukan (Masuk)</h2>
    <table>
        <thead><tr><th>#</th><th>Tanggal</th><th>Hari</th><th>Waktu</th><th>Barang</th><th>Qty</th><th>Aksi</th></tr></thead>
        <tbody>
        <?php if (empty($ins)): ?>
            <tr><td colspan="7">Tidak ada pemasukan.</td></tr>
        <?php else: foreach($ins as $t): ?>
            <?php
                // gunakan waktu dari dt jika ada, jika tidak fallback ke waktu server sekarang
                $rowTime = !empty($t['dt']) ? date('H:i:s', strtotime($t['dt'])) : date('H:i:s');
            ?>
            <tr>
                <td><?= $t['id'] ?></td>
                <td><?= date('Y-m-d H:i', strtotime($t['dt'])) ?></td>
                <td><?= htmlspecialchars($t['day']) ?></td>
                <td><?= $rowTime ?></td>
                <td><?= htmlspecialchars($t['item']) ?></td>
                <td><?= $t['qty'] ?></td>
                <td class="actions">
                    <a href="index.php?edit=<?= $t['id'] ?>">Edit</a>
                    <a href="?year=<?= $year ?>&month=<?= $month ?>&delete=<?= $t['id'] ?>" onclick="return confirm('Hapus transaksi?')">Hapus</a>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<div class="section">
    <h2>Pengeluaran (Keluar)</h2>
    <table>
        <thead><tr><th>#</th><th>Tanggal</th><th>Hari</th><th>Waktu</th><th>Barang</th><th>Qty</th><th>Aksi</th></tr></thead>
        <tbody>
        <?php if (empty($outs)): ?>
            <tr><td colspan="7">Tidak ada pengeluaran.</td></tr>
        <?php else: foreach($outs as $t): ?>
            <?php
                $rowTime = !empty($t['dt']) ? date('H:i:s', strtotime($t['dt'])) : date('H:i:s');
            ?>
            <tr>
                <td><?= $t['id'] ?></td>
                <td><?= date('Y-m-d H:i', strtotime($t['dt'])) ?></td>
                <td><?= htmlspecialchars($t['day']) ?></td>
                <td><?= $rowTime ?></td>
                <td><?= htmlspecialchars($t['item']) ?></td>
                <td><?= $t['qty'] ?></td>
                <td class="actions">
                    <a href="index.php?edit=<?= $t['id'] ?>">Edit</a>
                    <a href="?year=<?= $year ?>&month=<?= $month ?>&delete=<?= $t['id'] ?>" onclick="return confirm('Hapus transaksi?')">Hapus</a>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<!-- FORM: support add + edit -->
<?php if (!empty($editTransaction)): ?>
	<?php
		// provide edit timestamp (fallback ke server_now_ts jika invalid)
		$edit_ts = strtotime($editTransaction['dt']) ?: $server_now_ts;
	?>
	<!-- Edit form -->
	<form method="post" style="max-width:760px;padding:12px;border:1px solid #ddd;background:#fff7f0;margin-bottom:16px">
		<input type="hidden" name="action" value="edit">
		<input type="hidden" name="id" value="<?= (int)$editTransaction['id'] ?>">
		<h3>Edit Transaksi #<?= (int)$editTransaction['id'] ?></h3>
		<label>Tipe</label>
		<select name="type" required>
			<option value="in" <?= $editTransaction['type']==='in' ? 'selected':'' ?>>Pemasukan (Masuk)</option>
			<option value="out" <?= $editTransaction['type']==='out' ? 'selected':'' ?>>Pengeluaran (Keluar)</option>
		</select>
		<label>Nama Barang</label>
		<input name="item" required value="<?= htmlspecialchars($editTransaction['item'] ?? '', ENT_QUOTES) ?>">
		<label>Jumlah</label>
		<input name="qty" type="number" min="1" value="<?= (int)($editTransaction['qty'] ?? 1) ?>" required>
		<label>Tanggal & Waktu</label>
		<!-- include seconds and an id for JS sync -->
		<input id="edit-dt" name="dt" type="datetime-local" value="<?= date('Y-m-d\TH:i:s', $edit_ts) ?>">
		<div style="margin-top:8px">
			<button type="submit">Simpan Perubahan</button>
			<a href="<?= $_SERVER['PHP_SELF'] ?>" style="margin-left:8px">Batal</a>
		</div>
	</form>
<?php else: ?>
	<!-- TWO FORMS: Pemasokan (Masuk) & Pengeluaran (Keluar) -->
	<div style="display:flex;gap:12px;margin-bottom:16px">
		<form method="post" style="flex:1;padding:12px;border:1px solid #ddd;background:#f7fff7">
			<input type="hidden" name="action" value="add">
			<input type="hidden" name="type" value="in">
			<h3>Pemasokan (Masuk)</h3>
			<label>Nama Barang</label>
			<input name="item" required>
			<label>Jumlah</label>
			<input name="qty" type="number" min="1" value="1" required>
			<label>Tanggal & Waktu</label>
			<input name="dt" type="datetime-local" value="<?= date('Y-m-d\TH:i', $server_now_ts) ?>">
			<div style="margin-top:8px"><button type="submit">Simpan Pemasokan</button></div>
		</form>

		<form method="post" style="flex:1;padding:12px;border:1px solid #ddd;background:#fff7f7">
			<input type="hidden" name="action" value="add">
			<input type="hidden" name="type" value="out">
			<h3>Pengeluaran (Keluar)</h3>
			<label>Nama Barang</label>
			<input name="item" required>
			<label>Jumlah</label>
			<input name="qty" type="number" min="1" value="1" required>
			<label>Tanggal & Waktu</label>
			<input name="dt" type="datetime-local" value="<?= date('Y-m-d\TH:i', $server_now_ts) ?>">
			<div style="margin-top:8px"><button type="submit">Simpan Pengeluaran</button></div>
		</form>
	</div>
<?php endif; ?>

<p><a href="index.php">Kembali ke ringkasan</a></p>

<!-- JS: sync server clock and increment tiap detik -->
<script>
(function(){
    var ts = <?= (int)$server_now_ts ?> * 1000;
    function fmt(d){ return d.toLocaleTimeString('en-GB'); } // HH:MM:SS
    var el = document.getElementById('server-clock');
    function tick(){
        var d = new Date(ts);
        if(el) el.textContent = fmt(d);
        ts += 1000;
    }
    tick();
    setInterval(tick,1000);

    // ===== Edit datetime-local auto-follow (if present) =====
    var editEl = document.getElementById('edit-dt');
    if (editEl) {
        // edit timestamp in ms, initialized from server-provided PHP timestamp
        var editTs = <?= (int)$edit_ts ?> * 1000;
        var isFocused = false;
        editEl.addEventListener('focus', function(){ isFocused = true; });
        editEl.addEventListener('blur', function(){ isFocused = false; });

        function toInputValue(ms){
            var d = new Date(ms);
            // build YYYY-MM-DDTHH:MM:SS for datetime-local (includes seconds)
            function z(n){ return n < 10 ? '0'+n : n; }
            return d.getFullYear()+'-'+z(d.getMonth()+1)+'-'+z(d.getDate())+'T'+z(d.getHours())+':'+z(d.getMinutes())+':'+z(d.getSeconds());
        }

        // initialize input with server-side editTs (already set by PHP), ensure format
        try { editEl.value = toInputValue(editTs); } catch(e){}

        // update each second if user not editing the field
        setInterval(function(){
            if (!isFocused) {
                editTs += 1000;
                try { editEl.value = toInputValue(editTs); } catch(e){}
            }
        }, 1000);
    }
})();
</script>
</body>
</html>
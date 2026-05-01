<?php
/**
 * SPK PEMILIHAN OBJEK WISATA DI JAKARTA PUSAT
 * Fitur Tambahan: Search, Pagination, Chart Visualization, PDF Export, Decimal Consistency.
 */

session_start();
$db = new PDO('sqlite:wisata_jkt.db');

$current_page_file = basename($_SERVER['PHP_SELF']);

if (!file_exists('uploads')) {
    mkdir('uploads', 0777, true);
}

// Inisialisasi Database
$db->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, username TEXT, password TEXT)");
$db->exec("CREATE TABLE IF NOT EXISTS kriteria (id INTEGER PRIMARY KEY, nama TEXT, bobot REAL, tipe TEXT)");
$db->exec("CREATE TABLE IF NOT EXISTS wisata (
    id INTEGER PRIMARY KEY, 
    nama TEXT, 
    foto TEXT, 
    maps_link TEXT,
    c1 REAL, c2 REAL, c3 REAL, c4 REAL, c5 REAL, c6 REAL, c7 REAL, c8 REAL
)");

// User Default
$stmt = $db->query("SELECT COUNT(*) FROM users");
if ($stmt->fetchColumn() == 0) {
    $pass = password_hash('admin123', PASSWORD_DEFAULT);
    $db->exec("INSERT INTO users (username, password) VALUES ('admin', '$pass')");
}

// Kriteria Default
$stmt = $db->query("SELECT COUNT(*) FROM kriteria");
if ($stmt->fetchColumn() == 0) {
    $kriterias = [
        ['Harga Tiket', 5, 'cost'], ['Fasilitas', 4, 'benefit'], ['Aksesibilitas', 3, 'benefit'],
        ['Kebersihan', 4, 'benefit'], ['Keamanan', 4, 'benefit'], ['Spot Foto', 3, 'benefit'],
        ['Keramaian', 2, 'cost'], ['Jarak ke Pusat', 3, 'cost']
    ];
    foreach ($kriterias as $k) {
        $db->prepare("INSERT INTO kriteria (nama, bobot, tipe) VALUES (?, ?, ?)")->execute($k);
    }
}

if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header("Location: " . $current_page_file);
    exit();
}

if (isset($_POST['login'])) {
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$_POST['username']]);
    $res = $stmt->fetch();
    if ($res && password_verify($_POST['password'], $res['password'])) {
        $_SESSION['admin'] = $_POST['username'];
    }
}

// CRUD Admin
if (isset($_SESSION['admin'])) {
    if (isset($_POST['save_wisata'])) {
        $nama = $_POST['nama'];
        $foto_name = $_POST['old_foto'] ?: 'default.jpg';
        
        if (!empty($_FILES['foto']['name'])) {
            $tmp = $_FILES['foto']['tmp_name'];
            $foto_name = time() . '_' . preg_replace("/[^a-zA-Z0-9._]/", "", $_FILES['foto']['name']);
            move_uploaded_file($tmp, 'uploads/' . $foto_name);
        }

        $c = [$_POST['c1'], $_POST['c2'], $_POST['c3'], $_POST['c4'], $_POST['c5'], $_POST['c6'], $_POST['c7'], $_POST['c8']];
        
        if ($_POST['id'] == "") {
            $sql = "INSERT INTO wisata (nama, foto, maps_link, c1, c2, c3, c4, c5, c6, c7, c8) VALUES (?,?,'',?,?,?,?,?,?,?,?)";
            $db->prepare($sql)->execute(array_merge([$nama, $foto_name], $c));
        } else {
            $sql = "UPDATE wisata SET nama=?, foto=?, c1=?, c2=?, c3=?, c4=?, c5=?, c6=?, c7=?, c8=? WHERE id=?";
            $db->prepare($sql)->execute(array_merge([$nama, $foto_name], $c, [$_POST['id']]));
        }
        header("Location: " . $current_page_file);
        exit();
    }
    if (isset($_GET['delete'])) {
        $db->prepare("DELETE FROM wisata WHERE id = ?")->execute([$_GET['delete']]);
        header("Location: " . $current_page_file);
        exit();
    }
}
// PERHITUNGAN WP (Seluruh Data untuk Ranking)
$all_wisata_raw = $db->query("SELECT * FROM wisata")->fetchAll(PDO::FETCH_ASSOC);
$all_kriteria = $db->query("SELECT * FROM kriteria")->fetchAll(PDO::FETCH_ASSOC);

$rank = [];
if (count($all_wisata_raw) > 0) {
    // AMBIL BOBOT: Dari Input User (GET) atau dari Database (Admin)
    $total_bobot = 0;
    $custom_weights = [];
    foreach ($all_kriteria as $i => $k) {
        $val = $_GET['w'.($i+1)] ?? $k['bobot']; // Prioritaskan input wisatawan
        $custom_weights[] = $val;
        $total_bobot += $val;
    }

    $wj = [];
    foreach ($all_kriteria as $i => $k) {
        $val = $custom_weights[$i] / $total_bobot;
        $wj[] = ($k['tipe'] == 'cost') ? -$val : $val;
    }

    $sum_s = 0;
    $temp_s = [];
    foreach ($all_wisata_raw as $w) {
        $s = 1;
        for ($i=1; $i<=8; $i++) $s *= pow($w['c'.$i], $wj[$i-1]);
        $temp_s[$w['id']] = $s;
        $sum_s += $s;
    }

    foreach ($all_wisata_raw as $w) {
        $w['skor'] = ($sum_s != 0) ? $temp_s[$w['id']] / $sum_s : 0;
        $rank[] = $w;
    }
    usort($rank, fn($a, $b) => $b['skor'] <=> $a['skor']);
}

// FITUR SEARCH & PAGINATION PADA HASIL RANKING
$search = $_GET['q'] ?? '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 9;

// Filter rank berdasarkan search
$filtered_rank = array_filter($rank, function($item) use ($search) {
    return empty($search) || stripos($item['nama'], $search) !== false;
});

$total_results = count($filtered_rank);
$total_pages = ceil($total_results / $limit);
$offset = ($page - 1) * $limit;
$paginated_rank = array_slice($filtered_rank, $offset, $limit);

// Data untuk Grafik (Top 10)
$chart_labels = [];
$chart_data = [];
foreach (array_slice($rank, 0, 10) as $top) {
    $chart_labels[] = $top['nama'];
    $chart_data[] = round($top['skor'], 5);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SPK Wisata Jakarta Pusat</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root { --primary: #2c3e50; --accent: #3498db; --bg: #f1f5f9; }
        body { background: var(--bg); font-family: 'Inter', 'Segoe UI', sans-serif; color: #334155; }
        .hero { background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color: white; padding: 80px 0; border-bottom: 5px solid var(--accent); }
        .card-wisata { border: none; border-radius: 16px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1); transition: 0.3s; height: 100%; background: white; }
        .card-wisata:hover { transform: translateY(-5px); box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); }
        .img-box { height: 180px; position: relative; overflow: hidden; border-radius: 16px 16px 0 0; }
        .img-box img { width: 100%; height: 100%; object-fit: cover; transition: 0.5s; }
        .card-wisata:hover .img-box img { transform: scale(1.1); }
        .rank-tag { position: absolute; top: 12px; left: 12px; background: var(--accent); color: white; padding: 5px 14px; border-radius: 30px; font-weight: 700; font-size: 0.75rem; z-index: 10; }
        .score-badge { font-size: 1.25rem; color: var(--accent); font-weight: 800; letter-spacing: -0.5px; }
        .nav-custom { background: rgba(15, 23, 42, 0.9); backdrop-filter: blur(12px); border-bottom: 1px solid rgba(255,255,255,0.1); }
        .search-box { border-radius: 50px; padding-left: 20px; border: 1px solid #e2e8f0; }
        .criteria-pill { font-size: 0.65rem; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 2px 6px; color: #64748b; }
        
        @media print {
            .no-print, .nav-custom, .hero, .pagination, .search-container, .btn-group-admin { display: none !important; }
            body { background: white; padding: 0; }
            .container { max-width: 100% !important; width: 100% !important; margin: 0 !important; }
            .card-wisata { box-shadow: none; border: 1px solid #eee; break-inside: avoid; }
            .map-auto { display: none; }
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark nav-custom sticky-top no-print">
    <div class="container">
        <a class="navbar-brand fw-bold d-flex align-items-center" href="#">
            <span class="bg-primary p-1 rounded me-2">🏙️</span> SPK WISATA
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navContent">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navContent">
            <div class="ms-auto d-flex align-items-center gap-2 mt-3 mt-lg-0">
                <?php if(isset($_SESSION['admin'])): ?>
                    <button class="btn btn-primary btn-sm fw-bold px-3" data-bs-toggle="modal" data-bs-target="#modalWisata">+ Data Wisata</button>
                    <a href="?logout=1" class="btn btn-outline-danger btn-sm px-3">Logout</a>
                <?php else: ?>
                    <button class="btn btn-outline-light btn-sm px-4" data-bs-toggle="modal" data-bs-target="#modalLogin">Login Admin</button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>

<header class="hero text-center mb-5 no-print">
    <div class="container">
        <h1 class="fw-bold display-4 mb-3">Destinasi Terbaik Jakarta Pusat</h1>
        <p class="lead opacity-75 mb-4">Sistem Pendukung Keputusan Berbasis Weighted Product</p>
        <div class="d-flex justify-content-center gap-3">
            <button onclick="window.print()" class="btn btn-light px-4 fw-bold">🖨️ Cetak Laporan</button>
            <a href="#visualisasi" class="btn btn-outline-info px-4 fw-bold text-white">📊 Lihat Grafik</a>
        </div>
    </div>
</header>
<!-- FORM INPUT PREFERENSI (Menyesuaikan Use Case Wisatawan) -->
<div class="container mb-4 no-print">
    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-4">
            <h5 class="fw-bold mb-3">🎯 Sesuaikan Prioritas Anda</h5>
            <p class="small text-muted mb-4">Ubah bobot di bawah ini untuk mendapatkan rekomendasi yang lebih personal (Skala 1-5).</p>
            <form method="GET" class="row g-3">
                <input type="hidden" name="q" value="<?php echo htmlspecialchars($search); ?>">
                <?php foreach($all_kriteria as $i => $k): ?>
                <div class="col-6 col-md-3 col-lg-2">
                    <label class="form-label small mb-1"><?php echo $k['nama']; ?></label>
                    <input type="number" name="w<?php echo $i+1; ?>" class="form-control form-control-sm" 
                           value="<?php echo $_GET['w'.($i+1)] ?? $k['bobot']; ?>" min="1" max="5">
                </div>
                <?php endforeach; ?>
                <div class="col-12 text-end">
                    <button type="submit" class="btn btn-accent btn-sm text-white fw-bold px-4 shadow-sm" style="background-color: var(--accent);">
                        Update Rekomendasi
                    </button>
                    <a href="<?php echo $current_page_file; ?>" class="btn btn-light btn-sm border px-3">Reset</a>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="container mb-5">
    
    <!-- Visualisasi Section -->
    <div id="visualisasi" class="card border-0 shadow-sm rounded-4 mb-5 no-print">
        <div class="card-body p-4">
            <h5 class="fw-bold mb-4">📊 Statistik Top 10 Destinasi</h5>
            <canvas id="rankChart" height="100"></canvas>
        </div>
    </div>

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-center mb-4 gap-3 no-print">
        <h3 class="fw-bold m-0">Hasil Rekomendasi</h3>
        <form class="d-flex search-container" method="GET">
            <input type="text" name="q" class="form-control search-box" placeholder="Cari nama wisata..." value="<?php echo htmlspecialchars($search); ?>">
            <button type="submit" class="btn btn-primary ms-2 rounded-pill px-4">Cari</button>
        </form>
    </div>

    <?php if($total_results == 0): ?>
        <div class="text-center py-5">
            <p class="text-muted">Tidak ada data yang ditemukan.</p>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <?php foreach ($paginated_rank as $w): 
            // Cari index asli untuk label peringkat
            $original_rank = 0;
            foreach($rank as $r_idx => $r_val) {
                if($r_val['id'] == $w['id']) { $original_rank = $r_idx + 1; break; }
            }
        ?>
        <div class="col-md-6 col-lg-4">
            <div class="card card-wisata">
                <div class="img-box">
                    <div class="rank-tag">PERINGKAT #<?php echo $original_rank; ?></div>
                    <img src="uploads/<?php echo $w['foto']; ?>" onerror="this.src='https://placehold.co/600x400?text=<?php echo urlencode($w['nama']); ?>'" alt="Foto">
                </div>
                <div class="card-body p-4">
                    <h5 class="fw-bold text-dark mb-1 text-truncate"><?php echo htmlspecialchars($w['nama']); ?></h5>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="text-muted small">Vektor Score (V)</span>
                        <span class="score-badge"><?php echo number_format($w['skor'], 5); ?></span>
                    </div>

                    <div class="row g-1 mb-3">
                        <?php foreach($all_kriteria as $ki => $kv): ?>
                            <div class="col-6">
                                <div class="criteria-pill">
                                    <strong><?php echo substr($kv['nama'], 0, 10); ?>:</strong> <?php echo number_format($w['c'.($ki+1)], 1); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="map-auto no-print">
                        <iframe loading="lazy" src="https://www.google.com/maps?q=<?php echo urlencode($w['nama'] . ' Jakarta Pusat'); ?>&output=embed"></iframe>
                    </div>

                    <?php if(isset($_SESSION['admin'])): ?>
                    <div class="mt-3 pt-3 border-top d-flex gap-2 btn-group-admin">
                        <button class="btn btn-sm btn-light border flex-grow-1" onclick='editData(<?php echo json_encode($w); ?>)'>Edit</button>
                        <a href="?delete=<?php echo $w['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Hapus data?')">Hapus</a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <?php if($total_pages > 1): ?>
    <nav class="mt-5 no-print">
        <ul class="pagination justify-content-center">
            <?php for($i=1; $i<=$total_pages; $i++): ?>
                <li class="page-item <?php echo ($page == $i) ? 'active' : ''; ?>">
                    <a class="page-link" href="?q=<?php echo urlencode($search); ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                </li>
            <?php endfor; ?>
        </ul>
    </nav>
    <?php endif; ?>
</div>

<!-- Modal Login & CRUD tetap sama dengan penyesuaian UI -->
<div class="modal fade" id="modalLogin" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow-lg">
            <div class="modal-body p-4 text-center">
                <div class="mb-3 fs-1">🔐</div>
                <h5 class="fw-bold mb-4">Login Administrator</h5>
                <input type="text" name="username" class="form-control mb-2" placeholder="Username" required>
                <input type="password" name="password" class="form-control mb-3" placeholder="Password" required>
                <button type="submit" name="login" class="btn btn-primary w-100 shadow-sm py-2">Masuk Sekarang</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="modalWisata" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST" enctype="multipart/form-data" class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-light">
                <h5 class="fw-bold m-0" id="mTitle">Data Wisata Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" name="id" id="f_id">
                <input type="hidden" name="old_foto" id="f_old_foto">
                
                <div class="row mb-4">
                    <div class="col-md-7 mb-3">
                        <label class="form-label small fw-bold">Nama Objek Wisata</label>
                        <input type="text" name="nama" id="f_nama" class="form-control" required>
                    </div>
                    <div class="col-md-5 mb-3">
                        <label class="form-label small fw-bold">Upload Foto</label>
                        <input type="file" name="foto" class="form-control">
                    </div>
                </div>

                <div class="p-4 bg-light rounded-4 border">
                    <h6 class="fw-bold text-primary mb-3">Penilaian Kriteria (Skala 1 - 5)</h6>
                    <div class="row g-3">
                        <?php foreach($all_kriteria as $i => $k): ?>
                        <div class="col-md-3 col-6">
                            <label class="form-label mb-1 small text-muted"><?php echo $k['nama']; ?> (<?php echo strtoupper($k['tipe']); ?>)</label>
                            <input type="number" step="0.01" name="c<?php echo $i+1; ?>" id="f_c<?php echo $i+1; ?>" class="form-control" min="0" max="100" required>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 p-4 pt-0">
                <button type="submit" name="save_wisata" class="btn btn-primary px-5 shadow-sm py-2 fw-bold">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Chart Config
const ctx = document.getElementById('rankChart').getContext('2d');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: <?php echo json_encode($chart_labels); ?>,
        datasets: [{
            label: 'Skor Kedekatan (V)',
            data: <?php echo json_encode($chart_data); ?>,
            backgroundColor: 'rgba(52, 152, 219, 0.6)',
            borderColor: 'rgba(52, 152, 219, 1)',
            borderWidth: 2,
            borderRadius: 5
        }]
    },
    options: {
        responsive: true,
        scales: { y: { beginAtZero: true } },
        plugins: { legend: { display: false } }
    }
});

function editData(d) {
    document.getElementById('mTitle').innerText = 'Edit Wisata';
    document.getElementById('f_id').value = d.id;
    document.getElementById('f_nama').value = d.nama;
    document.getElementById('f_old_foto').value = d.foto;
    for(let i=1; i<=8; i++) document.getElementById('f_c' + i).value = d['c'+i];
    new bootstrap.Modal(document.getElementById('modalWisata')).show();
}

document.getElementById('modalWisata').addEventListener('hidden.bs.modal', function () {
    document.getElementById('mTitle').innerText = 'Data Wisata Baru';
    document.getElementById('f_id').value = '';
    document.getElementById('f_nama').value = '';
    for(let i=1; i<=8; i++) document.getElementById('f_c' + i).value = '';
});
</script>
</body>
</html>
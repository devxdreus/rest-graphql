Ringkasan Sistem yang Akan Dibangun
Aplikasi ini adalah sebuah Alat Uji Coba API Gateway yang dikembangkan menggunakan framework Laravel dan berjalan di lingkungan lokal Laragon. Sistem ini dirancang sebagai implementasi praktis dari metodologi penelitian untuk mengukur dan membandingkan performa API REST dan GraphQL secara dinamis.

Fungsi utamanya mencakup:

Dashboard Pengujian Interaktif: Menyediakan antarmuka pengguna (UI) di mana peneliti dapat memilih salah satu dari 14 skenario query yang telah ditentukan untuk dieksekusi.

Eksekutor Permintaan Paralel: Mengirimkan permintaan ke endpoint REST dan GraphQL (API GitHub & arXiv) secara bersamaan untuk setiap query yang diuji.

Evaluator Performa & Cache: Menganalisis hasil dari kedua API untuk menentukan pemenang berdasarkan waktu respons, kemudian menyimpan hasilnya di cache (Redis) untuk optimasi permintaan berikutnya.

Visualisasi Hasil: Menampilkan hasil perbandingan secara langsung di dashboard, termasuk API pemenang, metrik waktu, dan data JSON yang diterima.

Pencatatan & Riwayat (Logging): Menyimpan setiap hasil pengujian secara permanen ke dalam basis data (MariaDB/MySQL) untuk keperluan analisis data dan pelaporan.

Pengukuran CPU dan Memory Usage: Mengimplementasikan sistem untuk mengukur dan mencatat penggunaan CPU dan memori saat menjalankan query, sesuai dengan rumus yang ditentukan dalam metodologi penelitian.

Analisis Data Komprehensif: Menghitung rata-rata waktu respons dari 100 permintaan untuk setiap query dan menyajikan hasil dalam bentuk visualisasi grafik yang informatif.

Integrasi dengan JMeter: Menyediakan endpoint khusus untuk pengujian beban menggunakan Apache JMeter.

Persiapan Deployment ke AWS EC2: Menyediakan konfigurasi dan instruksi untuk deployment sistem ke lingkungan AWS EC2 Free Tier.

Pengujian dengan Repository Studi Kasus: Mengimplementasikan pengujian dengan 7 repository yang disebutkan dalam metodologi penelitian.

Arsitektur dan Tumpukan Teknologi (Technology Stack)
Sistem ini akan dibangun di atas tumpukan teknologi modern yang sepenuhnya didukung oleh Laragon untuk memastikan kemudahan pengembangan dan biaya yang minimal.

Lingkungan Pengembangan: Laragon, sebuah lingkungan pengembangan universal untuk Windows.

Framework Backend: Laravel, untuk membangun logika inti aplikasi, routing, dan kontrol.

Bahasa Pemrograman: PHP.

Basis Data: MariaDB / MySQL, untuk menyimpan log dan riwayat hasil pengujian.

In-Memory Cache: Redis, untuk implementasi Cache Manager yang cepat dan efisien.

Web Server: Nginx atau Apache (disediakan oleh Laragon).

Framework Frontend: Blade (template engine Laravel) dengan Tailwind CSS untuk membangun antarmuka pengguna yang responsif dan mudah digunakan.

Tools Pengujian: Apache JMeter untuk pengujian beban dan performa.

Cloud Deployment: AWS EC2 Free Tier untuk pengujian di lingkungan cloud.

Fitur Utama Aplikasi
Halaman Uji Coba (Testing Dashboard):

Rute: Route::get('/', [DashboardController::class, 'index']); dan Route::post('/test', [DashboardController::class, 'startTest']); di routes/web.php.

Controller: DashboardController untuk menampilkan view dan memproses data dari form pengujian.

View (Blade): dashboard.blade.php berisi dropdown untuk memilih query, tombol eksekusi, dan area presentasi hasil.

Inti Logika Gateway (Service Class):

ApiGatewayService akan mengimplementasikan logika dari Bab 3.3.4:

Parallel Request Executor: Menggunakan Http::pool() Laravel.

Performance Evaluator: Logika untuk membandingkan response_time dan status keberhasilan.

Cache Manager: Menggunakan Cache facade Laravel.

Fallback Mechanism: Logika untuk menangani kegagalan API.

Response Formatter: Menggunakan API Resources Laravel untuk memastikan output JSON yang konsisten.

Sistem Pengukuran CPU dan Memory:

Model & Migrasi: php artisan make:model PerformanceMetric -m.

Logika Pengukuran: Mengimplementasikan rumus CPU usage dan Memory usage dari Bab 3.3.8.

Controller: PerformanceMetricController untuk mengelola data metrik performa.

View (Blade): metrics.blade.php untuk menampilkan grafik performa.

Sistem Pencatatan ke Basis Data:

Model & Migrasi: php artisan make:model RequestLog -m.

Logika Penyimpanan: RequestLog::create([...]); dieksekusi setelah setiap pengujian selesai.

Halaman Riwayat Log (Log Viewer):

Rute: Route::get('/logs', [DashboardController::class, 'showLogs']);.

Controller: Method showLogs() akan mengambil data dari model RequestLog dengan paginasi.

View (Blade): logs.blade.php menampilkan data dalam bentuk tabel yang informatif.

Integrasi JMeter:

Rute: Route::post('/api/test', [ApiController::class, 'test']);.

Controller: ApiController untuk menerima dan memproses permintaan dari JMeter.

Konfigurasi: Menyediakan file konfigurasi JMX untuk JMeter.

Deployment ke AWS EC2:

Dokumentasi: Menyediakan instruksi step-by-step untuk deployment ke AWS EC2.

Script: Menyediakan script untuk otomatisasi deployment.

Pengujian Repository Studi Kasus:

Controller: RepositoryTestController untuk mengelola pengujian dengan repository studi kasus.

View (Blade): repositories.blade.php untuk menampilkan hasil pengujian.

Skema Basis Data (Tabel request_logs)
File migrasi akan membuat tabel dengan struktur berikut untuk mencatat setiap detail pengujian:

// dalam file migrasi database
Schema::create('request_logs', function (Blueprint $table) {
    $table->id();
    $table->string('query_id'); // ID Query, misal: 'Q1', 'Q2'
    $table->string('endpoint'); // Endpoint yang diuji
    $table->string('cache_status'); // 'HIT' atau 'MISS'
    $table->string('winner_api')->nullable(); // 'rest', 'graphql', atau 'none'
    $table->integer('rest_response_time_ms')->nullable();
    $table->integer('graphql_response_time_ms')->nullable();
    $table->boolean('rest_succeeded');
    $table->boolean('graphql_succeeded');
    $table->longText('response_body')->nullable();
    $table->timestamps();
});

// dalam file migrasi untuk metrik performa
Schema::create('performance_metrics', function (Blueprint $table) {
    $table->id();
    $table->string('query_id');
    $table->string('api_type'); // 'rest', 'graphql', atau 'integrated'
    $table->float('cpu_usage_percent');
    $table->float('memory_usage_percent');
    $table->integer('request_count');
    $table->float('avg_response_time_ms');
    $table->timestamps();
});

Parameter dan Metode Pengukuran
Sesuai dengan tujuan penelitian, aplikasi akan mengukur parameter berikut:

Waktu Respons (Response Time):

Metode: Diukur secara internal oleh aplikasi Laravel untuk setiap permintaan ke API REST dan GraphQL. Nilai ini adalah selisih waktu antara permintaan dikirim dan respons diterima, dalam milidetik (ms). Hasilnya akan disimpan di kolom rest_response_time_ms dan graphql_response_time_ms.

Rata-rata Waktu Respons:

Metode: Menggunakan rumus (1) dari Bab 3.3.8 untuk menghitung rata-rata waktu respons dari 100 permintaan untuk setiap query.

Rumus (1):
```
RTj = (1/100) * Σ {ts(respi) - ts(reqi)}, j = 1, 2, ..., 14
      1≤i≤100
```

Keterangan:
- RTj: Rata-rata waktu respons dalam kelompok uji ke-j
- Σ1≤i≤100: Penjumlahan waktu respons dari 100 permintaan (i)
- {ts(respi) - ts(reqi)}: Selisih antara waktu respons (ts(respi)) dan waktu request (ts(reqi)) tiap permintaan
- j = 1, 2, ..., 14: Penghitungan dilakukan untuk 14 kelompok uji

Penggunaan CPU:

Metode: Menggunakan rumus (2) dari Bab 3.3.8 untuk menghitung persentase penggunaan CPU.

Rumus (2):
```
CPU usage = (CPUused / CPUtotal) × 100
```

Keterangan:
- CPUused: CPU yang digunakan untuk menjalankan proses
- CPUtotal: CPU total yang tersedia

Di Windows: Menggunakan Windows Management Instrumentation (WMI) untuk mengakses data CPU.

Di Linux (AWS EC2): Menggunakan file /proc/stat untuk mengukur penggunaan CPU.

Penggunaan Memori:

Metode: Menggunakan rumus (3) dari Bab 3.3.8 untuk menghitung persentase penggunaan memori.

Rumus (3):
```
Memory usage = (Memused / Memtotal) × 100
```

Keterangan:
- Memused: Memori yang digunakan oleh sistem
- Memtotal: Total memori yang tersedia

Di Windows: Menggunakan Windows Management Instrumentation (WMI) untuk mengakses data memori.

Di Linux (AWS EC2): Menggunakan file /proc/meminfo untuk mengukur penggunaan memori.

Hasil pengukuran akan disajikan dalam bentuk tabel dan visualisasi grafik untuk mempermudah perbandingan, yang mencakup perbandingan ketiga pendekatan (REST, GraphQL, dan integrasi keduanya) untuk setiap metrik. Penelitian ini juga akan memanfaatkan insight dari literatur terkait untuk mendukung interpretasi hasil pengujian. Dengan pendekatan ini, penelitian bertujuan memberikan evaluasi komprehensif mengenai efisiensi dan kinerja metode integrasi REST dan GraphQL.

Daftar Skenario Query Pengujian
Aplikasi akan menyediakan 14 skenario pengujian berikut yang dapat dipilih melalui UI:

Q1 (Sederhana, Over-fetching): Mengambil nama dari 100 project teratas berdasarkan jumlah stars.

Q2 (Kompleks, Under-fetching): Untuk setiap project, mengambil jumlah total pull request dan isi dari 1.000 pull request terbaru.

Q3 (Sederhana, Under-fetching): Untuk setiap pull request, mengambil isi dari komentar.

Q4 (Sederhana, Over-fetching): Mengambil nama dan URL dari 5 project teratas berdasarkan jumlah stars.

Q5 (Sederhana, Over-fetching): Untuk tujuh project, mengambil jumlah commit, branch, bug, release, dan kontributor.

Q6 (Kompleks, Under-fetching): Untuk setiap project, mengambil judul dan isi dari bug yang sudah ditutup.

Q7 (Sederhana, Under-fetching): Untuk setiap bug yang sudah ditutup, mengambil isi dari komentar.

Q8 (Sederhana, Over-fetching): Mengambil nama dan URL dari project Java yang dibuat sebelum Januari 2012 dengan 10+ stars dan 1+ commit.

Q9 (Sederhana, Over-fetching): Mengambil jumlah stars dari project tertentu.

Q10 (Sederhana, Over-fetching): Mengambil nama repository dengan setidaknya 1.000 stars.

Q11 (Kompleks, Under-fetching): Mengambil jumlah commit dalam sebuah repository.

Q12 (Sederhana, Over-fetching): Untuk delapan project, mengambil jumlah release, stars, dan bahasa pemrograman yang digunakan.

Q13 (Kompleks, Under-fetching): Mengambil judul, isi, tanggal, dan nama project dari open issue yang ditandai dengan tag "bug".

Q14 (Kompleks, Under-fetching): Untuk setiap issue, mengambil isi dari komentar.

Repository Studi Kasus
Konteks pengujian didasarkan pada kebutuhan API dari repository berikut:

donnemartin/viz: Visualisasi repositori GitHub.

donnemartin/gitsome: Command line interface untuk GitHub.

csurfer/gitsuggest: Alat untuk menyarankan repositori GitHub.

guyzmo/git-repo: Command line interface untuk mengelola layanan Git.

vdaubry/github-awards: Peringkat repositori GitHub.

bibcure/arxivcheck: Alat untuk menghasilkan BIBTEX dari arXiv preprints.

karpathy/arxiv-sanity-preserver: Web interface untuk mencari submission arXiv.

Kebutuhan Teknis
Perangkat Lunak: Instalasi Laragon yang berfungsi dengan baik (mencakup Nginx/Apache, PHP, MariaDB/MySQL, Redis).

Proyek Laravel: composer create-project laravel/laravel api-gateway-penelitian.

Kunci API: Personal Access Token dari GitHub yang disimpan di file .env sebagai GITHUB_TOKEN.

Apache JMeter: Instalasi Apache JMeter untuk pengujian beban.

AWS Account: Akun AWS untuk deployment ke EC2 Free Tier.

Langkah Implementasi Berikutnya
1. Implementasi Pengukuran CPU dan Memory:
   - Buat model dan migrasi untuk PerformanceMetric
   - Implementasikan logika pengukuran CPU dan memory di ApiGatewayService
   - Tambahkan visualisasi grafik untuk metrik performa

2. Implementasi Analisis Data Komprehensif:
   - Tambahkan fitur untuk menjalankan 100 permintaan berturut-turut
   - Implementasikan rumus (1) untuk menghitung rata-rata waktu respons
   - Tambahkan visualisasi grafik perbandingan

3. Integrasi dengan JMeter:
   - Buat endpoint API untuk pengujian dengan JMeter
   - Buat file konfigurasi JMX untuk JMeter
   - Dokumentasikan cara menggunakan JMeter dengan sistem

4. Persiapan Deployment ke AWS EC2:
   - Buat dokumentasi step-by-step untuk deployment
   - Buat script otomatisasi deployment
   - Tambahkan konfigurasi untuk lingkungan produksi

5. Pengujian dengan Repository Studi Kasus:
   - Implementasikan pengujian dengan 7 repository yang disebutkan
   - Buat halaman untuk menampilkan hasil pengujian
   - Analisis dan bandingkan hasil dengan ekspektasi
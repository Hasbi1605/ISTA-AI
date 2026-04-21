# Issue Plan: Refactor Bertahap Prioritas 4 - Optimasi Login Blade Laravel

## Latar Belakang
Area auth Laravel masih menyisakan satu file view yang terlalu padat, yaitu [`login.blade.php`](</Users/macbookair/Magang-Istana/laravel/resources/views/livewire/pages/auth/login.blade.php:19>) dengan ukuran sekitar 583 baris. File ini bukan hanya template login, tetapi juga memegang class Volt, orkestrasi login, register, forgot password, pending registration berbasis cache, rate limit OTP, verifikasi OTP, modal OTP, dan shell UI auth dalam satu tempat.

Sebagian struktur memang sudah mulai dipisah ke partial seperti [`login-form.blade.php`](</Users/macbookair/Magang-Istana/laravel/resources/views/livewire/pages/auth/partials/login-form.blade.php:1>), [`register-form.blade.php`](</Users/macbookair/Magang-Istana/laravel/resources/views/livewire/pages/auth/partials/register-form.blade.php:1>), dan [`forgot-password-form.blade.php`](</Users/macbookair/Magang-Istana/laravel/resources/views/livewire/pages/auth/partials/forgot-password-form.blade.php:1>). Namun concern terbesar masih terkumpul di page Volt utama. Akibatnya, perubahan kecil di auth berisiko menyentuh banyak area sekaligus dan memperbesar biaya review, debugging, serta pengembangan lanjutan.

Prioritas refactor ini perlu dibatasi dengan ketat: merapikan boundary dan memecah concern `login.blade.php` tanpa mengubah route, UX auth, atau perilaku OTP yang sudah berjalan.

## Tujuan
- Mengurangi kepadatan `login.blade.php` dengan memecah concern yang saat ini menumpuk di satu file.
- Memisahkan tanggung jawab antara shell UI auth, flow state page, dan orkestrasi OTP/pending registration.
- Menjaga perilaku user-facing tetap sama untuk login, register, forgot password, resend OTP, cancel OTP, dan verify OTP.
- Memperjelas boundary agar perubahan auth berikutnya tidak harus mengedit satu file besar yang sensitif.
- Menambah atau menyesuaikan test pada flow auth yang paling rawan regresi.

## Ruang Lingkup
- Mempertahankan route auth yang ada, terutama [`/login`](</Users/macbookair/Magang-Istana/laravel/routes/auth.php:11>) yang mengarah ke `pages.auth.login`.
- Merapikan `pages.auth.login` agar tidak lagi menjadi gabungan class Volt + orchestration OTP + modal + shell UI dalam satu file besar.
- Mengekstrak bagian visual/presentasional yang masih besar dari [`login.blade.php`](</Users/macbookair/Magang-Istana/laravel/resources/views/livewire/pages/auth/login.blade.php:415>) ke partial yang lebih fokus.
- Mengekstrak atau memindahkan helper/orchestration pending registration dan OTP dari page Volt ke boundary yang lebih jelas bila diperlukan, misalnya helper/service/trait/action yang tetap kompatibel dengan Volt.
- Menjaga integrasi dengan [`LoginForm`](</Users/macbookair/Magang-Istana/laravel/app/Livewire/Forms/LoginForm.php:1>) dan mail [`VerificationCodeMail`](</Users/macbookair/Magang-Istana/laravel/app/Mail/VerificationCodeMail.php:1>) tetap stabil.
- Menambah atau memperluas test Laravel untuk flow auth yang disentuh refactor.

## Di Luar Scope
- Mendesain ulang tampilan halaman login atau mengganti visual direction auth.
- Mengubah copy UX auth secara besar-besaran.
- Mengubah route auth, redirect utama, atau middleware guest/auth.
- Mengganti mekanisme OTP, cache key, TTL, rate limit policy, atau strategi verifikasi email.
- Mengubah schema database user atau auth.
- Merombak halaman auth lain seperti `reset-password`, `verify-email`, atau `confirm-password` di tahap ini kecuali ada penyesuaian kecil yang benar-benar dibutuhkan.

## Area / File Terkait
- Page Volt utama:
  - [`/Users/macbookair/Magang-Istana/laravel/resources/views/livewire/pages/auth/login.blade.php`](/Users/macbookair/Magang-Istana/laravel/resources/views/livewire/pages/auth/login.blade.php)
- Partial yang sudah ada:
  - [`/Users/macbookair/Magang-Istana/laravel/resources/views/livewire/pages/auth/partials/login-form.blade.php`](/Users/macbookair/Magang-Istana/laravel/resources/views/livewire/pages/auth/partials/login-form.blade.php)
  - [`/Users/macbookair/Magang-Istana/laravel/resources/views/livewire/pages/auth/partials/register-form.blade.php`](/Users/macbookair/Magang-Istana/laravel/resources/views/livewire/pages/auth/partials/register-form.blade.php)
  - [`/Users/macbookair/Magang-Istana/laravel/resources/views/livewire/pages/auth/partials/forgot-password-form.blade.php`](/Users/macbookair/Magang-Istana/laravel/resources/views/livewire/pages/auth/partials/forgot-password-form.blade.php)
- Boundary auth pendukung:
  - [`/Users/macbookair/Magang-Istana/laravel/app/Livewire/Forms/LoginForm.php`](/Users/macbookair/Magang-Istana/laravel/app/Livewire/Forms/LoginForm.php)
  - [`/Users/macbookair/Magang-Istana/laravel/app/Mail/VerificationCodeMail.php`](/Users/macbookair/Magang-Istana/laravel/app/Mail/VerificationCodeMail.php)
  - [`/Users/macbookair/Magang-Istana/laravel/routes/auth.php`](/Users/macbookair/Magang-Istana/laravel/routes/auth.php)
- Test yang relevan:
  - [`/Users/macbookair/Magang-Istana/laravel/tests/Feature/Auth/AuthenticationTest.php`](/Users/macbookair/Magang-Istana/laravel/tests/Feature/Auth/AuthenticationTest.php)
  - [`/Users/macbookair/Magang-Istana/laravel/tests/Feature/Auth/RegistrationTest.php`](/Users/macbookair/Magang-Istana/laravel/tests/Feature/Auth/RegistrationTest.php)
  - [`/Users/macbookair/Magang-Istana/laravel/tests/Feature/Auth/PasswordResetTest.php`](/Users/macbookair/Magang-Istana/laravel/tests/Feature/Auth/PasswordResetTest.php)

## Risiko
- Auth adalah area user-facing dan kritikal; regresi kecil bisa memutus login atau registrasi.
- Karena `pages.auth.login` saat ini memegang beberapa mode view sekaligus (`login`, `register`, `forgot-password`, modal OTP), pemecahan yang tidak hati-hati bisa menimbulkan kebocoran state antar-mode.
- Orkestrasi OTP saat ini bergantung pada cache key, TTL, rate limiting, dan email side effect; boundary baru harus menjaga perilaku ini tetap identik.
- Refactor yang terlalu agresif berisiko berubah dari perapihan struktur menjadi redesign auth, padahal itu bukan tujuan issue ini.

## Langkah Implementasi
1. Tetapkan boundary refactor untuk page login.
   - Putuskan concern mana yang tetap berada di page Volt, dan mana yang dipindah ke partial/helper/service.
   - Jaga agar entry point `pages.auth.login` tetap menjadi pintu masuk route yang sama.
2. Pisahkan shell UI auth dari logic yang berat.
   - Keluarkan blok presentasional besar seperti wrapper page, branding, footer, dan modal OTP ke partial yang lebih fokus.
   - Tujuannya agar file utama lebih berperan sebagai composer, bukan file template raksasa.
3. Rapikan orchestration state auth.
   - Kurangi helper pendukung yang sekarang menumpuk di satu class Volt, terutama pending registration key, OTP resend/verify/cancel, dan state reset antar-view.
   - Jika perlu, pindahkan logic yang bukan tanggung jawab view ke helper/service/action yang jelas dan tetap mudah dipanggil dari Volt.
4. Jaga flow auth tetap identik.
   - Login tetap melalui [`LoginForm`](</Users/macbookair/Magang-Istana/laravel/app/Livewire/Forms/LoginForm.php:1>).
   - Register tetap masuk ke fase verifikasi OTP sebelum membuat akun aktif.
   - Forgot password, resend OTP, cancel verification, dan verify OTP tetap berperilaku sama seperti sebelum refactor.
5. Tambahkan safety net test bila ada gap.
   - Prioritaskan test yang mengunci flow auth kritikal daripada test struktur file.

## Rencana Test
- Jalankan full test Laravel:
  - `cd laravel && php artisan test`
- Pastikan suite auth tetap hijau, terutama:
  - render login screen
  - login sukses dan gagal
  - register membuka fase OTP tanpa langsung membuat akun aktif
  - OTP valid menyelesaikan registrasi dan login
  - cancel verification menjaga email tetap reusable
  - rate limit OTP verify dan resend tetap berjalan
  - forgot password flow tetap bekerja
- Tambahkan test baru hanya jika refactor membuka gap yang belum tercakup pada `AuthenticationTest`, `RegistrationTest`, atau `PasswordResetTest`.

## Kriteria Selesai
- `login.blade.php` jauh lebih ringan dan tidak lagi menjadi titik kumpul utama semua concern auth.
- Shell UI auth, modal OTP, dan orchestration pendukung punya boundary yang lebih jelas.
- Route dan UX user-facing tetap sama.
- Flow login/register/forgot-password/OTP tetap berjalan tanpa perubahan perilaku yang tidak direncanakan.
- Test Laravel yang relevan sudah dijalankan, dan test tambahan dibuat bila memang diperlukan untuk menjaga perilaku yang disentuh.

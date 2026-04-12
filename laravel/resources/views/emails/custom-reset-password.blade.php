<!DOCTYPE html>
<html>
<head>
    <title>Atur Ulang Kata Sandi</title>
</head>
<body style="font-family: Arial, sans-serif; background-color: #fafaf9; margin: 0; padding: 20px;">
    <div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 12px; padding: 40px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); text-align: center;">
        <h1 style="color: ista-primary; margin-bottom: 20px;">ISTA <span style="color: ista-gold; font-style: italic; font-weight: 300;">AI</span></h1>
        
        <h2 style="color: #333333; font-size: 24px; margin-bottom: 20px;">Atur Ulang Kata Sandi Anda</h2>
        
        <p style="color: #666666; line-height: 1.6; margin-bottom: 30px; text-align: left;">
            Anda menerima email ini karena kami menerima permintaan pengaturan ulang kata sandi untuk akun Anda.
        </p>
        
        <a href="{{ $url }}" style="display: inline-block; background-color: ista-primary; color: #ffffff; padding: 12px 24px; text-decoration: none; border-radius: 8px; font-weight: bold; margin-bottom: 30px;">
            Atur Ulang Kata Sandi
        </a>
        
        <p style="color: #666666; line-height: 1.6; margin-bottom: 30px; text-align: left;">
            Tautan atur ulang kata sandi ini akan kedaluwarsa dalam 60 menit.<br>
            Jika Anda tidak meminta pengaturan ulang kata sandi, tidak ada tindakan lebih lanjut yang diperlukan.
        </p>

        <div style="margin-top: 40px; padding-top: 20px; border-top: 1px solid #eeeeee; color: #999999; font-size: 12px; text-align: left;">
            Jika Anda kesulitan mengklik tombol "Atur Ulang Kata Sandi", salin dan tempel URL di bawah ini ke peramban web Anda:<br>
            <a href="{{ $url }}" style="color: ista-primary; word-break: break-all;">{{ $url }}</a>
        </div>
    </div>
</body>
</html>
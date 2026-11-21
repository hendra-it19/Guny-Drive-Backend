<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<title>Verifikasi email GunyDrive</title>
</head>
<body class="canvas" style="margin:0;padding:32px 12px;background:{{ $colors['background'] }};font:16px/1.6 Arial,sans-serif;color:{{ $colors['text'] }};">
<table role="presentation" class="card" width="100%" align="center" cellpadding="0" cellspacing="0" style="max-width:520px;background:{{ $colors['surface'] }};border:1px solid #dce1f4;border-radius:20px;color:{{ $colors['text'] }};">
<tr><td style="height:6px;background:{{ $colors['accent'] }};border-radius:20px 20px 0 0;"></td></tr>
<tr><td style="padding:28px;">
<strong class="brand" style="color:{{ $colors['accent'] }};">GunyDrive</strong>
<h1 style="margin:16px 0;font-size:25px;line-height:1.25;">Verifikasi alamat email Anda</h1>
<p>Halo {{ $name }},</p>
<p>Gunakan <strong>kode verifikasi 6 digit</strong> berikut di aplikasi GunyDrive, atau klik tombol verifikasi di bawah untuk mengaktifkan akun Anda. Berlaku selama {{ $minutes }} menit.</p>
@if(!empty($code))
<div style="margin:22px 0;padding:18px 20px;background:{{ $colors['background'] }};border:1px dashed {{ $colors['accent'] }};border-radius:16px;text-align:center;">
<div style="font-size:12px;text-transform:uppercase;letter-spacing:1.5px;opacity:0.75;margin-bottom:6px;">Kode Verifikasi 6 Digit</div>
<div style="font-size:32px;font-weight:800;letter-spacing:8px;color:{{ $colors['accent'] }};font-family:'Courier New',monospace;">{{ $code }}</div>
</div>
@endif
<p style="margin:24px 0;text-align:center;"><a href="{{ $url }}" style="display:inline-block;background:{{ $colors['accent'] }};color:{{ $colors['onAccent'] }};padding:12px 24px;border-radius:24px;text-decoration:none;font-weight:bold;">Verifikasi email saya</a></p>
<p style="font-size:14px;">Jika tombol sulit dibuka, salin tautan berikut ke browser:</p>
<p style="font-size:13px;word-break:break-all;"><a class="link" style="color:{{ $colors['accent'] }};" href="{{ $url }}">{{ $url }}</a></p>
<p style="margin:24px 0 0;font-size:13px;">Jika Anda tidak membuat akun GunyDrive atau mengubah alamat email, abaikan pesan ini.</p>
</td></tr></table></body></html>

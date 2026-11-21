GunyDrive · Verifikasi alamat email

Halo {!! $name !!},

@if(!empty($code))
Kode Verifikasi 6 Digit Anda: {{ $code }}

@endif
Atau konfirmasikan alamat email Anda melalui tautan berikut dalam {{ $minutes }} menit:

{!! $url !!}

Jika Anda tidak membuat akun GunyDrive atau mengubah alamat email, abaikan pesan ini.

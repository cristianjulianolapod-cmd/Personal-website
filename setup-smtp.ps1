$ErrorActionPreference = 'Stop'

$username = (Read-Host 'Alamat Gmail pengirim').Trim()
if ($username -notmatch '^[^@\s]+@[^@\s]+\.[^@\s]+$') {
    throw 'Alamat email tidak valid.'
}

$securePassword = Read-Host 'Google App Password (input tersembunyi)' -AsSecureString
$passwordPointer = [IntPtr]::Zero

try {
    $passwordPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)
    $appPassword = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($passwordPointer).Replace(' ', '')
    if ($appPassword.Length -lt 16) {
        throw 'Google App Password tidak valid. Masukkan App Password yang dibuat di akun Google.'
    }

    [Environment]::SetEnvironmentVariable('GADGETHUB_SMTP_USERNAME', $username, 'User')
    [Environment]::SetEnvironmentVariable('GADGETHUB_SMTP_PASSWORD', $appPassword, 'User')

    $savedUsername = [Environment]::GetEnvironmentVariable('GADGETHUB_SMTP_USERNAME', 'User')
    $savedPassword = [Environment]::GetEnvironmentVariable('GADGETHUB_SMTP_PASSWORD', 'User')
    if ($savedUsername -ne $username -or $savedPassword -ne $appPassword) {
        throw 'Environment variable SMTP gagal disimpan.'
    }

    Write-Output 'Konfigurasi SMTP tersimpan untuk akun Windows ini.'
    Write-Output 'Tutup seluruh XAMPP Control Panel, buka kembali, lalu jalankan Apache.'
    Write-Output 'Password tidak ditampilkan dan tidak ditulis ke berkas skrip.'
}
finally {
    if ($passwordPointer -ne [IntPtr]::Zero) {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($passwordPointer)
    }
    $appPassword = $null
    $securePassword = $null
}

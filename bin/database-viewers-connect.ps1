param(
    [string]$Distribution = 'Ubuntu-24.04',
    [string]$SshTarget = 'root@179.66.133.169'
)
$ErrorActionPreference = 'Stop'
if ($SshTarget -notmatch '^[a-zA-Z0-9_.-]+@[a-zA-Z0-9_.-]+$') {
    throw 'Usa usuario@servidor sin opciones ni espacios.'
}
# Usa la identidad SSH ya instalada en WSL. El socket permite repetir sin
# crear túneles duplicados; no guarda contraseñas ni añade tareas de inicio.
$controlSocket = '~/.ssh/sysforest-database-viewers-%C.sock'
$sshBase = @('-d', $Distribution, '--', 'ssh', '-o', 'ForwardAgent=no', '-o', 'BatchMode=yes', '-S', $controlSocket)
$previousPreference = $ErrorActionPreference
$ErrorActionPreference = 'Continue'
& wsl @sshBase -O check $SshTarget 2>$null
$tunnelStatus = $LASTEXITCODE
$ErrorActionPreference = $previousPreference
if ($tunnelStatus -ne 0) {
    & wsl @sshBase -M -f -N -o ExitOnForwardFailure=yes -o ServerAliveInterval=30 -o ServerAliveCountMax=3 `
        -L 127.0.0.1:18084:127.0.0.1:18084 -L 127.0.0.1:18085:127.0.0.1:18085 $SshTarget
    if ($LASTEXITCODE -ne 0) { throw 'No se pudo abrir el túnel. Revisa la identidad SSH o si los puertos ya están ocupados.' }
}
Start-Process 'http://127.0.0.1:18084'
Start-Process 'http://127.0.0.1:18085'
Write-Host 'Visores conectados. Las credenciales están en /opt/sysforest/private/database-viewers/access.txt del servidor.'

# Fix ColdAisle SNMP task to run via cmd.exe /c (elevated PowerShell)
$task = 'ColdAisle SNMP Poll'
$cmd  = 'C:\Windows\System32\cmd.exe'
$bat  = 'C:\inetpub\wwwroot\ColdAisle\scripts\run_poll_snmp.cmd'
Stop-ScheduledTask -TaskName $task -ErrorAction SilentlyContinue
Get-Process php -ErrorAction SilentlyContinue | Stop-Process -Force
schtasks /Delete /TN $task /F 2>$null
$tr = "`"$cmd`" /c `"$bat`""
schtasks /Create /TN $task /TR $tr /SC MINUTE /MO 1 /RU SYSTEM /RL HIGHEST /F
schtasks /Change /TN $task /ENABLE
Start-ScheduledTask -TaskName $task
Start-Sleep 20
Get-ScheduledTaskInfo -TaskName $task | Format-List LastRunTime, LastTaskResult, State
Get-Content C:\inetpub\wwwroot\ColdAisle\storage\logs\snmp_poll_cli.log -Tail 15
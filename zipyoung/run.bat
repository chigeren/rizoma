@echo off
color F0
cls
echo zipyoung - portable RIZOMA agent
echo.

set MODE=%1
if "%MODE%"=="" set MODE=oneshot

"%~dp0php\php.exe" -c "%~dp0php\php.ini" "%~dp0zipyoung.php" %MODE%

echo.
pause

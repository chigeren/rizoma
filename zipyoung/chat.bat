@echo off
color F0
cls
echo zipyoung [chat] - interactive. Type 'q' to quit.
echo.
"%~dp0php\php.exe" -c "%~dp0php\php.ini" "%~dp0zipyoung.php" chat
echo.
pause

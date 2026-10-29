@echo off
REM SecurePay — hidupkan server (kekal berjalan selagi tetingkap ini dibuka)
C:\xampp\php\php.exe -S 0.0.0.0:8099 -t "%~dp0"
pause

@ECHO OFF
setlocal DISABLEDELAYEDEXPANSION
SET BIN_TARGET=%~dp0/../hollodotme/fast-cgi-client/bin/fcgiget
php "%BIN_TARGET%" %*

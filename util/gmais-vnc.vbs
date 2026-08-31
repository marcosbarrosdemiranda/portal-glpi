' gmais-vnc.vbs — roda o handler PowerShell sem piscar janela de console.
' Registrado como:  wscript.exe "C:\Util\gmais-vnc.vbs" "%1"
Dim sh, uri
Set sh = CreateObject("WScript.Shell")
uri = ""
If WScript.Arguments.Count > 0 Then uri = WScript.Arguments(0)
sh.Run "powershell -NoProfile -ExecutionPolicy Bypass -File ""C:\Util\gmais-vnc.ps1"" """ & uri & """", 0, False

' gmais-rdp.vbs — roda o handler RDP sem piscar janela de console.
' Registrado como:  wscript.exe "C:\Util\gmais-rdp.vbs" "%1"
Dim sh, uri
Set sh = CreateObject("WScript.Shell")
uri = ""
If WScript.Arguments.Count > 0 Then uri = WScript.Arguments(0)
sh.Run "powershell -NoProfile -ExecutionPolicy Bypass -File ""C:\Util\gmais-rdp.ps1"" """ & uri & """", 0, False

' Lanza un comando artisan del POS sin ventana de consola visible.
'
' Por que existe: si el worker corriera en una ventana de consola, cualquiera podria
' cerrarla sin querer y la caja dejaria de sincronizar sin que nadie se entere.
'
' El tercer parametro de Run es True (esperar): el script queda vivo mientras corre el
' proceso. Eso es lo que permite que el Programador de tareas sepa que la tarea sigue
' en ejecucion y no lance una segunda copia.

Set fso = CreateObject("Scripting.FileSystemObject")
Set sh = CreateObject("WScript.Shell")

sh.CurrentDirectory = fso.GetParentFolderName(WScript.ScriptFullName)

argumentos = ""
For i = 0 To WScript.Arguments.Count - 1
    argumentos = argumentos & " " & WScript.Arguments(i)
Next

sh.Run "php artisan" & argumentos, 0, True

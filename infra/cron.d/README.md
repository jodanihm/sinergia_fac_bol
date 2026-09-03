# Crons del host, versionados

Lo que hay aquí son los archivos que van en `/etc/cron.d/` del servidor. **No los
edites en el servidor**: edítalos aquí y el despliegue los instala.

## Por qué existe este directorio

`/etc/cron.d/sinergia-pagos` se creó a mano cuando se puso en marcha el cobro en
línea. Funcionaba, pero no estaba en ninguna parte: si el host se reconstruye o
se migra, ese archivo no viaja y **el conciliador desaparece sin que nadie se
entere**. Y el conciliador es la única red que recupera un pago que Flow cobró y
cuyo aviso se perdió — una caída de red, un despliegue en el segundo equivocado.
Un módulo que mueve dinero no puede depender de un archivo que solo existe en un
servidor.

## Cuál se administra desde aquí

Solo `sinergia-pagos`, a propósito. Los otros cuatro crons del proyecto
(`sinergia-correos`, `sinergia-respaldos`, `sinergia-ordenes-compra`,
`sinergia-veredictos`) siguen viviendo únicamente en el host. Traerlos también
sería lo correcto, pero es otro trabajo: cada uno tiene su propia historia y su
propio riesgo al reinstalarse, y mezclarlo con esto haría que un despliegue
tocara cinco archivos del sistema en vez de uno.

## Cómo se instala

`deploy.sh` compara el archivo del repo con el instalado en cada despliegue. Si
faltan o difieren, lo instala con `install -o root -g root -m 0644` y **verifica
después**: que exista, que sea `root:root`, que tenga permisos `0644` y que el
contenido coincida. Si algo de eso no cuadra, el despliegue aborta.

Con `--dry-run` no escribe nada: dice lo que haría.

No hace falta reiniciar `cron`: los archivos de `/etc/cron.d/` se releen solos.

## Si cambias el archivo

El contenido manda desde aquí. Un cambio a mano en el servidor se revierte en el
siguiente despliegue, que es justamente lo que se quería.

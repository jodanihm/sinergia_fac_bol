<?php /* Ayuda comun a los dos libros: va bajo la fila de Ventas y Compras, a todo el ancho. */ ?>
<details class="tarjeta">
    <summary>El SII rechaza un libro con "LNC" (Tipo de Envio de Libro No Corresponde)</summary>
    <p>No se puede reenviar un libro <strong>TOTAL</strong> del mismo periodo tributario: el SII lo
    rechaza con LNC. Para corregir un libro ya enviado del mismo periodo, reenvialo con
    <code>tipoLibro = RECTIFICA</code> (manteniendo <code>tipoEnvio = TOTAL</code>). RECTIFICA es un
    tipo de LIBRO, no un tipo de ENVIO. Verificado en vivo: el SII acepta el reenvio con
    tipoLibro=RECTIFICA (trackIds 0253053136 y 0253053470).</p>
    <p>El estado <strong>LSO</strong> ("Schema de Envio de Libro Correcto") NO es final: el SII
    recibio el libro y lo esta procesando. Vuelve a consultar hasta que pase a <strong>LOK</strong>
    (aceptado cuadrado) o <strong>LTC</strong> (aceptado con reparos); puede tardar varios minutos,
    sobre todo en libros RECTIFICA.</p>
</details>

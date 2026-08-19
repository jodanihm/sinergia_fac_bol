<?php
/**
 * Landing publica del sitio. Es lo que ve en / quien NO tiene sesion; con
 * sesion abierta el router manda a /panel antes de llegar aqui.
 *
 * NO PASA POR partials/header.php, a proposito. Esa cabecera monta el panel
 * (su sidebar, sus tokens de :root, su style.css de 120 KB) y esta pantalla no
 * es panel: trae su propio sistema de diseno -- Industry, en /css/landing.css,
 * con sus tokens y las dos familias Barlow autoalojadas en /fonts/. Mezclar
 * las dos hojas daria dos :root peleando por las mismas variables. Por eso la
 * vista es un documento HTML completo, de <!DOCTYPE> a </html>.
 *
 * ORIGEN. El diseno viene exportado como un solo archivo de 3,3 MB
 * (/data/sinergia/landing/landing.html en el VPS, fuera del repo) que traia
 * todo -- HTML, CSS, fuentes, imagenes y React -- en base64 dentro de un
 * desempaquetador JavaScript. Ese formato sirve para pasarse el diseno, no
 * para servirlo: son 3,3 MB antes del primer pixel, y sin JavaScript la
 * pagina queda en blanco. Aqui esta desarmado en archivos de verdad, que es lo
 * que hace que el navegador pueda cachear cada pieza por separado y que la
 * landing no dependa de JavaScript para mostrarse. El cuerpo es HTML plano: el
 * export no usaba ni un componente React (el bundle del sistema de diseno
 * venia con "components":[] ).
 *
 * EL LOGO Y LA MASCOTA NO SE DUPLICARON. El export traia los suyos, pero
 * resultaron ser byte a byte los que ya estaban en /img/logo.png y
 * /img/sinergin.png. Se reusan los del panel. De la mascota se sirve una copia
 * a 480 px (/img/landing/sinergin-480.png): el original mide 1230x1278 y pesa
 * 1,5 MB para mostrarse a 220 px, y sinergin-240.png -- el del banner del
 * asistente -- se queda corto para los 2x de esta pantalla.
 *
 * "INGRESAR" APUNTA AL LOGIN DE VERDAD, /login, el mismo de siempre. En el
 * export eran dos enlaces absolutos a https://facturacion.sinergiaia.cl/login
 * con target="_blank"; ahora que la landing vive en el mismo sitio son rutas
 * relativas y en la misma pestana. Los demas enlaces salientes (WhatsApp,
 * mailto) conservan su target="_blank", que ahi si corresponde.
 */

// Mismo criterio que partials/header.php con /css/style.css: una URL fija deja
// servida la version anterior desde la cache del navegador o desde Cloudflare
// tras un despliegue. filemtime cambia en cada build de la imagen.
$cssRuta    = __DIR__ . '/../public/css/landing.css';
$cssVersion = @filemtime($cssRuta);
$cssHref    = '/css/landing.css' . ($cssVersion ? '?v=' . $cssVersion : '');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SinergIA -- Facturacion electronica ante el SII para PyMEs</title>
<meta name="description" content="Emite facturas, boletas y notas de credito y debito directo al SII. Control de folios y CAF, envio automatico a tus clientes, informes y facturacion masiva desde Excel.">
<link rel="icon" href="/img/logo.png">
<link rel="stylesheet" href="<?= htmlspecialchars($cssHref); ?>">
</head>
<body>
<div style="max-width:1240px;margin:0 auto;padding:0 clamp(20px,5vw,64px)">

  <!-- NAV -->
  <nav style="display:flex;align-items:center;flex-wrap:wrap;gap:16px;padding:20px 0">
    <img width="254" height="80" src="/img/logo.png" alt="SinergIA SpA" style="height:36px;width:auto;display:block;margin-right:auto">

    <a href="#funcionalidades" style="text-decoration:none;font-size:14px;font-weight:500">Funcionalidades</a>
    <a href="#asistente" style="text-decoration:none;font-size:14px;font-weight:500">Asistente IA</a>
    <a href="#planes" style="text-decoration:none;font-size:14px;font-weight:500">Planes</a>
    <a href="/login" style="text-decoration:none;font-size:14px;font-weight:500">Ingresar</a>
    <a class="btn btn-primary" href="https://wa.me/56978542249?text=Hola%2C%20quiero%20una%20demo%20de%20SinergIA%20Facturaci%C3%B3n%20Electr%C3%B3nica" target="_blank" rel="noopener">Hablar por WhatsApp</a>
  </nav>

  <!-- HERO -->
  <section style="display:flex;flex-wrap:wrap;align-items:center;gap:clamp(32px,6vw,64px);padding:clamp(32px,6vw,72px) 0">
    <div style="flex:1 1 420px;min-width:320px">
      <h1 style="font-family:var(--font-heading);font-weight:600;text-transform:uppercase;font-size:clamp(34px,5.2vw,58px);line-height:1.05;letter-spacing:0.005em;margin:0 0 20px">
        Facturación electrónica, sin vueltas
      </h1>
      <p style="font-size:18px;line-height:1.6;color:color-mix(in srgb, var(--color-text) 82%, transparent);max-width:52ch;margin:0 0 28px">
        Emite facturas, boletas, notas de crédito y débito directo al SII, desde una plataforma pensada para PyMEs — y capaz de facturar cientos de documentos de una sola vez cuando tu volumen crece.
      </p>
      <div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:20px">
        <a class="btn btn-primary" style="font-size:15px;padding:12px 22px" href="https://wa.me/56978542249?text=Hola%2C%20quiero%20una%20demo%20de%20SinergIA%20Facturaci%C3%B3n%20Electr%C3%B3nica" target="_blank" rel="noopener">Quiero una demo</a>
        <a class="btn btn-secondary" style="font-size:15px;padding:12px 22px" href="#planes">Ver planes y precios</a>
      </div>
      <p style="font-size:13px;color:color-mix(in srgb, var(--color-text) 60%, transparent);margin:0">
        ¿Prefieres probarlo tú mismo? <a href="/login">Entra a la demo en vivo →</a>
      </p>
    </div>
    <div class="blueprint" style="flex:1 1 420px;min-width:300px;max-width:560px">
      <i class="corner tl"></i><i class="corner tr"></i><i class="corner bl"></i><i class="corner br"></i>
      <img width="1733" height="868" src="/img/landing/panel-gestion.png" alt="Panel de gestión de SinergIA con ventas, IVA y folios disponibles" style="width:100%;height:auto;display:block">
    </div>
  </section>

  <!-- PROBLEMA / BENEFICIO -->
  <section style="padding:clamp(24px,4vw,48px) 0">
    <span style="display:block;font-size:13px;letter-spacing:0.08em;text-transform:uppercase;font-weight:600;color:var(--color-accent-700);margin-bottom:8px">Por qué SinergIA</span>
    <hr style="height:1px;border:0;background:var(--color-divider);margin:0 0 32px">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:clamp(20px,3vw,32px)">
      <div class="blueprint" style="padding:24px">
        <i class="corner tl"></i><i class="corner tr"></i><i class="corner bl"></i><i class="corner br"></i>
        <h3 style="font-size:20px;text-transform:uppercase;margin:0 0 10px">Se acabó facturar a mano</h3>
        <p style="font-size:15px;line-height:1.6;color:color-mix(in srgb, var(--color-text) 78%, transparent);margin:0">Olvídate de las planillas y los sistemas enredados. Emites tu documento al SII en minutos, desde el computador o el celular.</p>
      </div>
      <div class="blueprint" style="padding:24px">
        <i class="corner tl"></i><i class="corner tr"></i><i class="corner bl"></i><i class="corner br"></i>
        <h3 style="font-size:20px;text-transform:uppercase;margin:0 0 10px">Nunca te quedas sin folios</h3>
        <p style="font-size:15px;line-height:1.6;color:color-mix(in srgb, var(--color-text) 78%, transparent);margin:0">SinergIA administra tus folios y CAF por ti, y te avisa antes de que se agoten. Sin sorpresas a mitad de mes.</p>
      </div>
      <div class="blueprint" style="padding:24px">
        <i class="corner tl"></i><i class="corner tr"></i><i class="corner bl"></i><i class="corner br"></i>
        <h3 style="font-size:20px;text-transform:uppercase;margin:0 0 10px">Volumen alto, sin drama</h3>
        <p style="font-size:15px;line-height:1.6;color:color-mix(in srgb, var(--color-text) 78%, transparent);margin:0">¿Cientos de documentos al mes? Subes un Excel y se emiten todos juntos. Nada de hacerlo uno por uno.</p>
      </div>
    </div>
  </section>

  <!-- FUNCIONALIDADES -->
  <section id="funcionalidades" style="padding:clamp(32px,5vw,56px) 0">
    <span style="display:block;font-size:13px;letter-spacing:0.08em;text-transform:uppercase;font-weight:600;color:var(--color-accent-700);margin-bottom:8px">Funcionalidades</span>
    <hr style="height:1px;border:0;background:var(--color-divider);margin:0 0 12px">
    <h2 style="font-size:clamp(26px,3.2vw,36px);text-transform:uppercase;margin:0 0 32px;max-width:20ch">Todo lo que necesitas para facturar, en un solo lugar</h2>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:20px;margin-bottom:48px">
      <div class="card" style="border:1px solid var(--color-divider)">
        <span class="card-title">Facturas, boletas y notas</span>
        <p class="card-body">Emisión de facturas, facturas exentas, notas de crédito y de débito, directo al SII.</p>
      </div>
      <div class="card" style="border:1px solid var(--color-divider)">
        <span class="card-title">Envío automático</span>
        <p class="card-body">Cada documento llega solo al correo de tu cliente, sin que tengas que reenviarlo tú.</p>
      </div>
      <div class="card" style="border:1px solid var(--color-divider)">
        <span class="card-title">Folios y CAF</span>
        <p class="card-body">Carga y control de tus folios sin planillas aparte ni llamadas al contador.</p>
      </div>
      <div class="card" style="border:1px solid var(--color-divider)">
        <span class="card-title">Informes y dashboard</span>
        <p class="card-body">Ventas por día, facturación por tipo de documento, tus mejores clientes y el estado de cada emisión.</p>
      </div>
      <div class="card" style="border:1px solid var(--color-divider)">
        <span class="card-title">Libros IECV</span>
        <p class="card-body">Tus libros de compra y venta listos, sin armarlos a mano cada mes.</p>
      </div>
      <div class="card" style="border:1px solid var(--color-divider)">
        <span class="card-title">Certificación ante el SII</span>
        <p class="card-body">Te acompañamos en el proceso de certificación si tu empresa recién empieza a facturar electrónico.</p>
      </div>
    </div>

    <div style="display:flex;flex-wrap:wrap-reverse;align-items:center;gap:clamp(28px,5vw,56px)">
      <div class="blueprint" style="flex:1 1 420px;min-width:300px;max-width:560px">
        <i class="corner tl"></i><i class="corner tr"></i><i class="corner bl"></i><i class="corner br"></i>
        <img loading="lazy" decoding="async" width="1862" height="902" src="/img/landing/panel-emision.png" alt="Panel de emisión con el listado de documentos, folio, receptor, montos y estado ante el SII" style="width:100%;height:auto;display:block">
      </div>
      <div style="flex:1 1 380px;min-width:300px">
        <h3 style="font-size:24px;text-transform:uppercase;margin:0 0 14px">Sabes en qué está cada documento</h3>
        <p style="font-size:16px;line-height:1.65;color:color-mix(in srgb, var(--color-text) 78%, transparent);margin:0;max-width:50ch">El panel de emisión te muestra folio, receptor, neto, IVA y estado ante el SII de cada factura o boleta — filtra por fecha, tipo o cliente en segundos.</p>
      </div>
    </div>
  </section>

  <!-- ASISTENTE IA -->
  <section id="asistente" style="padding:clamp(32px,5vw,56px) 0;border-top:1px solid var(--color-divider)">
    <span style="display:block;font-size:13px;letter-spacing:0.08em;text-transform:uppercase;font-weight:600;color:var(--color-accent-700);margin-bottom:8px">El diferenciador</span>
    <hr style="height:1px;border:0;background:var(--color-divider);margin:0 0 32px">
    <div style="display:flex;flex-wrap:wrap;align-items:center;gap:clamp(28px,5vw,56px)">
      <div style="flex:1 1 380px;min-width:300px">
        <h2 style="font-size:clamp(26px,3.2vw,34px);text-transform:uppercase;margin:0 0 16px">Le hablas como a una persona, y prepara el borrador</h2>
        <p style="font-size:16px;line-height:1.65;color:color-mix(in srgb, var(--color-text) 78%, transparent);margin:0 0 20px;max-width:50ch">Le escribes en lenguaje normal — "hazme una factura para Pérez por arriendo de software, 25 mil más IVA" — y el Asistente IA arma el borrador. También responde preguntas sobre tu facturación: "cuánto vendí en julio", "cuál fue mi mejor cliente".</p>
        <div class="blueprint" style="display:inline-flex;align-items:center;gap:10px;padding:12px 18px">
          <i class="corner tl"></i><i class="corner tr"></i><i class="corner bl"></i><i class="corner br"></i>
          <svg width="18" height="18" sc-camel-view-box="0 0 24 24" fill="none" stroke="var(--color-accent-700)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12l2 2 4-4"></path><circle cx="12" cy="12" r="9"></circle></svg>
          <span style="font-size:14px;font-weight:500;color:var(--color-accent-800)">Nunca emite solo: tú revisas y emites cada documento</span>
        </div>
      </div>
      <div style="flex:1 1 420px;min-width:300px;max-width:560px;display:flex;align-items:center;gap:20px;justify-content:center">
        <img loading="lazy" decoding="async" width="480" height="499" src="/img/landing/sinergin-480.png" alt="Sinergín, el asistente de IA de SinergIA" style="width:min(220px,40%);height:auto;flex:none">
        <div class="blueprint" style="flex:1 1 260px;min-width:220px">
          <i class="corner tl"></i><i class="corner tr"></i><i class="corner bl"></i><i class="corner br"></i>
          <img loading="lazy" decoding="async" width="1688" height="880" src="/img/landing/asistente-ia.png" alt="Asistente IA de SinergIA respondiendo preguntas sobre la facturación de la empresa" style="width:100%;height:auto;display:block">
        </div>
      </div>
    </div>
  </section>

  <!-- FACTURACIÓN MASIVA -->
  <section style="padding:clamp(32px,5vw,56px) 0;border-top:1px solid var(--color-divider)">
    <span style="display:block;font-size:13px;letter-spacing:0.08em;text-transform:uppercase;font-weight:600;color:var(--color-accent-700);margin-bottom:8px">Para volumen alto</span>
    <hr style="height:1px;border:0;background:var(--color-divider);margin:0 0 32px">
    <div style="display:flex;flex-wrap:wrap-reverse;align-items:center;gap:clamp(28px,5vw,56px)">
      <div class="blueprint" style="flex:1 1 420px;min-width:300px;max-width:560px">
        <i class="corner tl"></i><i class="corner tr"></i><i class="corner bl"></i><i class="corner br"></i>
        <img loading="lazy" decoding="async" width="1656" height="849" src="/img/landing/carga-masiva.png" alt="Pantalla de facturación masiva mostrando folios disponibles y notas pendientes de emisión" style="width:100%;height:auto;display:block">
      </div>
      <div style="flex:1 1 380px;min-width:300px">
        <h2 style="font-size:clamp(26px,3.2vw,34px);text-transform:uppercase;margin:0 0 16px">Cientos de documentos, de una sola vez</h2>
        <p style="font-size:16px;line-height:1.65;color:color-mix(in srgb, var(--color-text) 78%, transparent);margin:0 0 20px;max-width:50ch">Sube tu Excel con las notas de venta y SinergIA revisa folios disponibles, arma los documentos y los emite al SII en lotes — sin que tengas que abrir uno por uno.</p>
        <a class="btn btn-secondary" href="https://wa.me/56978542249?text=Hola%2C%20quiero%20saber%20m%C3%A1s%20sobre%20facturaci%C3%B3n%20masiva" target="_blank" rel="noopener">Conversemos sobre tu volumen</a>
      </div>
    </div>
  </section>

  <!-- PLANES -->
  <section id="planes" style="padding:clamp(32px,5vw,56px) 0;border-top:1px solid var(--color-divider)">
    <span style="display:block;font-size:13px;letter-spacing:0.08em;text-transform:uppercase;font-weight:600;color:var(--color-accent-700);margin-bottom:8px">Planes</span>
    <hr style="height:1px;border:0;background:var(--color-divider);margin:0 0 12px">
    <h2 style="font-size:clamp(26px,3.2vw,36px);text-transform:uppercase;margin:0 0 8px">Elige según cuánto facturas al mes</h2>
    <p style="font-size:15px;color:color-mix(in srgb, var(--color-text) 70%, transparent);margin:0 0 36px">Todos los valores son más IVA.</p>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(270px,1fr));gap:24px;align-items:stretch">

      <div class="blueprint" style="padding:28px;display:flex;flex-direction:column">
        <i class="corner tl"></i><i class="corner tr"></i><i class="corner bl"></i><i class="corner br"></i>
        <h3 style="font-size:20px;text-transform:uppercase;margin:0 0 16px">Básico</h3>
        <div style="margin-bottom:20px"><span style="font-family:var(--font-heading);font-weight:600;font-size:40px">0,5 UF</span><span style="font-size:14px;color:color-mix(in srgb, var(--color-text) 65%, transparent)"> /mes + IVA</span></div>
        <a class="btn btn-secondary btn-block" href="https://wa.me/56978542249?text=Hola%2C%20me%20interesa%20el%20plan%20B%C3%A1sico" target="_blank" rel="noopener">Comenzar</a>
        <ul style="list-style:none;padding:0;margin:24px 0 0;display:flex;flex-direction:column;gap:12px">
          <li style="display:flex;gap:10px;font-size:14px"><svg width="16" height="16" sc-camel-view-box="0 0 24 24" fill="none" stroke="var(--color-accent)" stroke-width="1.5" style="flex:none;margin-top:2px"><path d="M20 6L9 17l-5-5"></path></svg>Hasta 100 facturas al mes</li>
          <li style="display:flex;gap:10px;font-size:14px"><svg width="16" height="16" sc-camel-view-box="0 0 24 24" fill="none" stroke="var(--color-accent)" stroke-width="1.5" style="flex:none;margin-top:2px"><path d="M20 6L9 17l-5-5"></path></svg>Factura, boleta, notas de crédito y débito</li>
          <li style="display:flex;gap:10px;font-size:14px"><svg width="16" height="16" sc-camel-view-box="0 0 24 24" fill="none" stroke="var(--color-accent)" stroke-width="1.5" style="flex:none;margin-top:2px"><path d="M20 6L9 17l-5-5"></path></svg>Carga de folios CAF</li>
          <li style="display:flex;gap:10px;font-size:14px"><svg width="16" height="16" sc-camel-view-box="0 0 24 24" fill="none" stroke="var(--color-accent)" stroke-width="1.5" style="flex:none;margin-top:2px"><path d="M20 6L9 17l-5-5"></path></svg>Informes y panel de control</li>
        </ul>
      </div>

      <div class="blueprint" style="padding:28px;display:flex;flex-direction:column;border-color:var(--color-accent);border-width:2px;position:relative">
        <i class="corner tl"></i><i class="corner tr"></i><i class="corner bl"></i><i class="corner br"></i>
        <span class="tag tag-accent" style="position:absolute;top:-13px;left:24px;background:var(--color-accent);color:var(--color-bg)">MÁS ELEGIDO</span>
        <h3 style="font-size:20px;text-transform:uppercase;margin:0 0 16px">PyME</h3>
        <div style="margin-bottom:20px"><span style="font-family:var(--font-heading);font-weight:600;font-size:40px">0,8 UF</span><span style="font-size:14px;color:color-mix(in srgb, var(--color-text) 65%, transparent)"> /mes + IVA</span></div>
        <a class="btn btn-primary btn-block" href="https://wa.me/56978542249?text=Hola%2C%20me%20interesa%20el%20plan%20PyME" target="_blank" rel="noopener">Comenzar</a>
        <ul style="list-style:none;padding:0;margin:24px 0 0;display:flex;flex-direction:column;gap:12px">
          <li style="display:flex;gap:10px;font-size:14px"><svg width="16" height="16" sc-camel-view-box="0 0 24 24" fill="none" stroke="var(--color-accent)" stroke-width="1.5" style="flex:none;margin-top:2px"><path d="M20 6L9 17l-5-5"></path></svg>Hasta 400 facturas al mes</li>
          <li style="display:flex;gap:10px;font-size:14px"><svg width="16" height="16" sc-camel-view-box="0 0 24 24" fill="none" stroke="var(--color-accent)" stroke-width="1.5" style="flex:none;margin-top:2px"><path d="M20 6L9 17l-5-5"></path></svg>Envío automático de correo a tus clientes</li>
          <li style="display:flex;gap:10px;font-size:14px"><svg width="16" height="16" sc-camel-view-box="0 0 24 24" fill="none" stroke="var(--color-accent)" stroke-width="1.5" style="flex:none;margin-top:2px"><path d="M20 6L9 17l-5-5"></path></svg>Todo lo del plan Básico</li>
          <li style="display:flex;gap:10px;font-size:14px"><svg width="16" height="16" sc-camel-view-box="0 0 24 24" fill="none" stroke="var(--color-accent)" stroke-width="1.5" style="flex:none;margin-top:2px"><path d="M20 6L9 17l-5-5"></path></svg>Múltiples usuarios</li>
          <li style="display:flex;gap:10px;font-size:14px"><svg width="16" height="16" sc-camel-view-box="0 0 24 24" fill="none" stroke="var(--color-accent)" stroke-width="1.5" style="flex:none;margin-top:2px"><path d="M20 6L9 17l-5-5"></path></svg>Soporte prioritario</li>
        </ul>
      </div>

      <div class="blueprint" style="padding:28px;display:flex;flex-direction:column">
        <i class="corner tl"></i><i class="corner tr"></i><i class="corner bl"></i><i class="corner br"></i>
        <h3 style="font-size:20px;text-transform:uppercase;margin:0 0 16px">Pro</h3>
        <div style="margin-bottom:20px"><span style="font-family:var(--font-heading);font-weight:600;font-size:40px">1,5 UF</span><span style="font-size:14px;color:color-mix(in srgb, var(--color-text) 65%, transparent)"> /mes + IVA</span></div>
        <a class="btn btn-secondary btn-block" href="https://wa.me/56978542249?text=Hola%2C%20me%20interesa%20el%20plan%20Pro" target="_blank" rel="noopener">Hablemos</a>
        <ul style="list-style:none;padding:0;margin:24px 0 0;display:flex;flex-direction:column;gap:12px">
          <li style="display:flex;gap:10px;font-size:14px"><svg width="16" height="16" sc-camel-view-box="0 0 24 24" fill="none" stroke="var(--color-accent)" stroke-width="1.5" style="flex:none;margin-top:2px"><path d="M20 6L9 17l-5-5"></path></svg>Facturación ilimitada</li>
          <li style="display:flex;gap:10px;font-size:14px"><svg width="16" height="16" sc-camel-view-box="0 0 24 24" fill="none" stroke="var(--color-accent)" stroke-width="1.5" style="flex:none;margin-top:2px"><path d="M20 6L9 17l-5-5"></path></svg>Facturación masiva desde Excel</li>
          <li style="display:flex;gap:10px;font-size:14px"><svg width="16" height="16" sc-camel-view-box="0 0 24 24" fill="none" stroke="var(--color-accent)" stroke-width="1.5" style="flex:none;margin-top:2px"><path d="M20 6L9 17l-5-5"></path></svg>Todo lo del plan PyME</li>
        </ul>
      </div>

    </div>

    <p style="font-size:14px;color:color-mix(in srgb, var(--color-text) 70%, transparent);margin:28px 0 0">
      Si tu empresa aún no está habilitada como facturador electrónico ante el SII, la configuración e implementación tiene un costo único de <strong>2 UF + IVA</strong>.
      ¿Prefieres escribir? <a href="mailto:contacto@sinergiaia.cl">contacto@sinergiaia.cl</a>
    </p>
  </section>

  <!-- CIERRE -->
  <section style="padding:clamp(40px,6vw,64px) 0;border-top:1px solid var(--color-divider)">
    <div class="blueprint" style="padding:clamp(28px,4vw,48px);display:flex;flex-wrap:wrap;gap:32px;align-items:center;justify-content:space-between">
      <i class="corner tl"></i><i class="corner tr"></i><i class="corner bl"></i><i class="corner br"></i>
      <div style="flex:1 1 320px;min-width:260px">
        <h2 style="font-size:clamp(24px,3vw,32px);text-transform:uppercase;margin:0 0 12px">Empieza a facturar sin dolores de cabeza</h2>
        <p style="font-size:15px;line-height:1.6;color:color-mix(in srgb, var(--color-text) 78%, transparent);margin:0;max-width:48ch">Escríbenos por WhatsApp y te ayudamos a elegir el plan según cuánto facturas.</p>
      </div>
      <div style="display:flex;flex-wrap:wrap;gap:12px">
        <a class="btn btn-primary" style="font-size:15px;padding:12px 22px" href="https://wa.me/56978542249?text=Hola%2C%20quiero%20una%20demo%20de%20SinergIA%20Facturaci%C3%B3n%20Electr%C3%B3nica" target="_blank" rel="noopener">Hablar por WhatsApp</a>
        <a class="btn btn-secondary" style="font-size:15px;padding:12px 22px" href="mailto:contacto@sinergiaia.cl">contacto@sinergiaia.cl</a>
      </div>
    </div>
  </section>

  <footer style="padding:28px 0 40px;border-top:1px solid var(--color-divider);display:flex;flex-wrap:wrap;gap:16px;justify-content:space-between;align-items:center">
    <span style="font-size:13px;color:color-mix(in srgb, var(--color-text) 60%, transparent)">© 2026 SinergIA SpA — Facturación electrónica DTE ante el SII</span>
    <div style="display:flex;gap:16px">
      <a href="https://wa.me/56978542249" target="_blank" rel="noopener" style="font-size:13px;text-decoration:none">WhatsApp +56 9 7854 2249</a>
      <a href="mailto:contacto@sinergiaia.cl" style="font-size:13px;text-decoration:none">contacto@sinergiaia.cl</a>
    </div>
  </footer>

</div>
</body>
</html>

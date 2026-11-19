# Lo que necesitás completar para publicar Free

Paquete: QuoteFlow Free 1.0.0-rc.1. Hacer estas pruebas en staging, con datos ficticios y pasarela de pruebas. El ZIP todavía no es una versión estable aprobada para producción.

## 1. Preparar la tienda de staging

- Hosting con HTTPS, WordPress 7.0 o superior, PHP 8.3/8.4 y WooCommerce 11.3.0 como combinación de referencia.
- Base MySQL/MariaDB con InnoDB y `GET_LOCK`. Si el proveedor bloquea esta función, informar el error; no desactivar la protección contra duplicados.
- Instalar el ZIP y conservar una copia de seguridad de archivos y base antes de actualizar.
- Configurar moneda, decimales, países de venta/entrega y las tasas que usa tu comercio.
- Excluir las páginas con formularios y enlaces `qf_offer` de la caché externa. Los enlaces privados no deben registrarse en analítica ni compartirse en reportes públicos.
- Mantener WordPress cron operativo o configurar su ejecución desde el hosting.

Entregar: versiones de PHP/WordPress/WooCommerce, tema, plugins relevantes y resultado de instalación/actualización.

## 2. Revisar la interfaz real

La herramienta de navegador integrada no estuvo disponible en este entorno. Revisar en computadora y móvil, con el tema real:

- Producto simple y variable: opciones, cantidades y solicitud enviada.
- Carrito clásico y de bloques: solicitud con varias líneas.
- Oferta: tabla, total, vencimiento, botones y legibilidad.
- Direcciones: entrega, facturación diferente y cambio de país antes de completar los campos.
- Mensajes de campos incompletos, stock insuficiente, rechazo, cancelación y vencimiento.
- Administración: filtros, paginación, ofertas manuales y enlace al pedido.
- Idioma español y navegación por teclado, incluido foco visible.

Entregar: navegador/dispositivo y capturas sin datos personales ni enlaces privados, con los problemas encontrados.

## 3. Configurar y comprobar el correo

Configurar tu proveedor SMTP/API directamente en WordPress y autenticar el dominio remitente según las instrucciones del proveedor. Elegir una casilla propia como comprador de prueba.

- Crear una oferta y enviar una vez desde QuoteFlow.
- Confirmar recepción en la casilla y revisar spam, remitente, asunto y enlace.
- Abrir el enlace recibido y verificar que corresponde a la oferta correcta.
- Guardar el identificador de entrega del proveedor y comprobar que reabrir la oferta no reenvía el mensaje.
- Si falla el envío, revisar el registro del proveedor antes de reintentar.

Entregar: proveedor, resultado y registro de entrega sin credenciales ni token privado. La aceptación local del transporte no sustituye esta comprobación externa.

## 4. Certificar una pasarela sandbox

Elegir la pasarela que usarán las tiendas (por ejemplo, la que ya está instalada), activar su modo de pruebas y configurar las notificaciones/webhooks que requiera su plugin. Esta prueba corresponde al pago de pedidos WooCommerce; el Merchant of Record de Pro pertenece a otra fase.

Ejecutar y registrar:

1. Pago aprobado de una oferta física con impuestos y envío.
2. Pago rechazado y nuevo intento sobre el mismo pedido.
3. Pago pendiente y confirmación posterior por webhook.
4. Doble clic en aceptar/pagar: un único pedido y un único cobro.
5. Redirección o autenticación adicional si la pasarela la utiliza.
6. Cancelación y devolución desde WooCommerce, verificando estado y stock.
7. Carrito mixto y producto variable con opciones correctas.

Entregar: nombre/versión del plugin de pago, IDs de pruebas, estados resultantes, totales, stock y evidencia del webhook. No activar credenciales de producción para estas pruebas.

## 5. Hacer el piloto y cerrar soporte

- Probar con 2–3 tiendas piloto que cubran los casos anteriores y sus temas/plugins reales.
- Confirmar los ejemplos de cálculo con las tasas que usa cada tienda: los precios negociados son totales con impuestos incluidos.
- Definir soporte público y responsable de responder incidencias. El repositorio ofrece Issues como punto inicial.
- Definir conservación de cotizaciones, pedidos, respaldos y registros de correo, usando las opciones de privacidad de WooCommerce.
- Confirmar si la distribución estable será por WordPress.org. Para ese canal: cuenta del titular, usuario contribuidor, nombre/slug disponible y material de la ficha (descripción, capturas e icono si se utilizará).

## 6. Habilitar el lanzamiento

Cuando las pruebas anteriores estén aprobadas, pasar sus resultados para resolver cualquier hallazgo y revisar la candidata final. Solo entonces cambiar a `1.0.0`, revisar el PR de `dev` a `main`, crear el tag y publicar el paquete estable. Las versiones de desarrollo no deben actualizar automáticamente instalaciones estables.

No necesitás terminar Pro, la API de licencias ni la web comercial para validar esta Free.

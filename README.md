# QuoteFlow Free

Primer corte local del plugin. En **WooCommerce → QuoteFlow**, un operador crea una oferta para un producto y copia el enlace privado. El comprador acepta y continúa al pago nativo de WooCommerce. Repetir la aceptación devuelve el mismo pedido.

Esta versión de desarrollo admite un producto simple virtual por oferta, invitados, tiendas con impuestos desactivados y una vigencia de 24 horas. El importe es el total negociado de la línea. El enlace otorga acceso durante la vigencia y se comparte de forma privada. El envío del enlace es manual. La tienda gestiona el pago.

La conversión registra su intención antes de crear el pedido. Si el pedido se guardó y el vínculo se interrumpió, el plugin intenta recuperarlo por un marcador de WooCommerce. Si la búsqueda no identifica un único pedido válido, la conversión se detiene para revisión. El pago vuelve a validar el producto, el stock y el total.

Para pruebas, copiar el plugin a la tienda descartable de M0, activarlo y ejecutar `python3 tests/smoke.py RUTA_AL_COMPOSE_DE_M0`. La prueba cambia el modo de almacenamiento y crea datos ficticios. Usar exclusivamente la tienda de pruebas.

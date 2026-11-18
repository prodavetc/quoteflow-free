# QuoteFlow Free

Plugin WooCommerce en desarrollo para recibir solicitudes de cotización, preparar una oferta y convertir su aceptación en un pedido pagable.

## Recorrido disponible

1. El comprador solicita una cotización desde la ficha de un producto compatible o desde su carrito con varios productos.
2. El comerciante abre **WooCommerce → QuoteFlow**, revisa la solicitud e ingresa el total negociado de cada línea.
3. El comerciante pulsa **Send offer by email** para enviar la oferta al correo del comprador. También puede copiar y compartir el enlace privado.
4. El comprador acepta y continúa al pago nativo de WooCommerce.

La bandeja permite recuperar el enlace de una oferta creada desde una solicitud. Una respuesta repetida conserva la oferta original. El formulario independiente de creación de ofertas sigue disponible.

## Alcance de esta versión

Versión de desarrollo 0.4.0-dev. Admite entre 1 y 20 productos simples virtuales diferentes por solicitud u oferta, compradores invitados, tiendas con impuestos desactivados y ofertas de 24 horas. Cada importe es el total negociado para la cantidad de su línea. El total de la oferta suma esos importes. El comerciante decide cuándo enviar la oferta y la tienda gestiona el pago.

El formulario se agrega después del formulario clásico de compra. También se puede insertar con un bloque Shortcode de WordPress usando `[quoteflow_request product_id="123"]`, donde 123 es el ID de un producto compatible. La integración automática de producto se verificó con el tema de bloques Twenty Twenty-Five y el bloque WooCommerce Add to Cart Form. El carrito incorpora un formulario para cotizar todos sus productos tanto con el bloque Cart como con el shortcode clásico de WooCommerce. Para otra página, insertar [quoteflow_request cart="yes"] en un bloque Shortcode.

Las solicitudes admiten cantidades enteras de 1 a 100 por producto, correo y una nota de hasta 2000 caracteres. El servidor valida el producto, protege el formulario con nonce y campo antispam, y limita cada dirección de conexión a cinco solicitudes por ventana de diez minutos. Guarda una huella temporal derivada de la dirección y la ventana. Si la tienda usa un proxy, revisar su configuración antes de la beta para evitar compartir el límite entre compradores.

Las páginas de producto y las páginas con el shortcode envían encabezados de exclusión de caché. Configurar también la exclusión en cualquier caché externa que ignore estos encabezados.

## Correo de ofertas

Después de crear una oferta, la pantalla muestra el destinatario y el botón **Send offer by email**. Para una oferta de la bandeja, abrir **View link and email**. El envío usa el servicio de correo ya configurado en WordPress y el formato de WooCommerce, con líneas, importes, moneda, vencimiento y enlace privado.

Crear o recuperar una oferta conserva el estado de correo hasta que el operador solicite enviarla. La bandeja muestra el estado y la base registra los intentos y la fecha en que el servicio acepta el mensaje. La aceptación del servicio de correo permite verificar que se solicitó el envío. La recepción final depende del servidor del destinatario.

Un bloqueo por oferta y el número de intento impiden que un doble clic o un formulario antiguo repitan el envío. Si el servicio rechaza el mensaje, revisar la configuración y reintentar desde el formulario actualizado. Si una interrupción impide conocer el resultado, comprobar los registros del servicio y confirmar explícitamente el nuevo intento. Las ofertas vencidas o ya convertidas a pedidos quedan excluidas del envío.

Los registros anteriores comienzan con el correo pendiente de solicitud. La actualización conserva los datos y espera una acción explícita del operador.

## Persistencia y reintentos

La versión actualiza el esquema al cargar el plugin y conserva las solicitudes y ofertas anteriores. Los registros de un solo producto de versiones anteriores se leen usando sus columnas originales. Las solicitudes nuevas guardan un snapshot de todas sus líneas y cada oferta conserva también sus importes. Las sumas se calculan en unidades enteras de una millonésima de moneda y se validan contra la precisión configurada en WooCommerce. Las solicitudes se guardan localmente y se identifican por una clave de formulario única. Reenviar el mismo formulario con el mismo contenido conserva una sola solicitud.

Cada solicitud puede tener una única oferta. Un bloqueo por solicitud y un índice único evitan ofertas duplicadas. El enlace de esas ofertas se deriva de una clave privada de WordPress y un identificador de solicitud. Cambiar las claves de seguridad de WordPress afecta su recuperación desde la bandeja. Los enlaces ya compartidos conservan su validación por hash hasta vencer.

La conversión registra su intención antes de crear el pedido. Si el pedido se guardó y el vínculo se interrumpió, el plugin intenta recuperarlo por un marcador de WooCommerce. Si la búsqueda no identifica un único pedido válido, la conversión se detiene para revisión. El pago vuelve a validar el producto, el stock y el total.

## Pruebas

Copiar el plugin a la tienda descartable de M0 y activarlo. Ejecutar las pruebas de forma secuencial.

```sh
python3 tests/smoke.py RUTA_AL_COMPOSE_DE_M0
python3 tests/requests.py RUTA_AL_COMPOSE_DE_M0
python3 tests/multiline.py RUTA_AL_COMPOSE_DE_M0
python3 tests/mail_flow.py RUTA_AL_COMPOSE_DE_M0
```

Las pruebas cambian el modo de almacenamiento entre HPOS y clásico, habilitan el acceso público de la tienda local y crean datos ficticios. Usar exclusivamente la tienda de pruebas.

La primera cubre concurrencia, reintentos, recuperación de conversiones, falta de stock y expiración. La segunda recorre formularios públicos y de administración hasta el pago local, e incluye solicitudes y respuestas repetidas, permisos, validación y límite de solicitudes. La tercera recorre un carrito de varios productos, precios por línea, conversión y pago con HPOS y almacenamiento clásico. Comprueba falta de stock en una línea, alteraciones de precios que mantienen el total, recuperación tras una interrupción y lectura de registros anteriores. También prueba el carrito de bloques y el clásico. La transferencia bancaria se confirma mediante una simulación local.

La prueba de correo instala temporalmente un capturador local que intercepta todos los envíos y se retira al finalizar. Verifica contenido, permisos, formulario inválido, envíos simultáneos, fallos y recuperación de resultados inciertos. Esta prueba verifica la integración de WordPress con el transporte capturado.

Quedan pendientes productos físicos y variables, impuestos, integración nativa con otros bloques, entrega con un servicio real de correo, privacidad y borrado de datos, revisión visual y certificación con una pasarela en sandbox antes de la beta.

## Desarrollo y producción

El trabajo diario y las pruebas se realizan en `dev`. Los cambios se promueven a `main` mediante un pull request revisado cuando se aprueba una versión para producción. Las descargas estables se publican desde tags revisados de `main`. Los paquetes de desarrollo usan un canal beta o de desarrollo.

Después de clonar el repositorio, activar la protección local.

```sh
git switch dev
git config core.hooksPath .githooks
```

El hook local bloquea pushes a `main`, incluidos los intentos de borrado. La protección del servidor debe configurarse también en GitHub según las prestaciones del plan de la cuenta. En las aplicaciones desplegadas, la rama de producción se configura explícitamente como `main` y las pruebas usan entornos independientes.

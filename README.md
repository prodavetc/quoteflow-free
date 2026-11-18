# QuoteFlow Free

Plugin WooCommerce en desarrollo para recibir solicitudes de cotización, preparar una oferta y convertir su aceptación en un pedido pagable.

## Recorrido disponible

1. El comprador completa el formulario de cotización en la ficha de un producto compatible.
2. El comerciante abre **WooCommerce → QuoteFlow**, revisa la solicitud e ingresa el total negociado.
3. El comerciante copia el enlace privado y lo comparte con el comprador.
4. El comprador acepta y continúa al pago nativo de WooCommerce.

La bandeja permite recuperar el enlace de una oferta creada desde una solicitud. Una respuesta repetida conserva la oferta original. El formulario independiente de creación de ofertas sigue disponible.

## Alcance de esta versión

Versión 0.2.0. Admite un producto simple virtual por solicitud u oferta, compradores invitados, tiendas con impuestos desactivados y ofertas de 24 horas. El importe es el total negociado de la línea. El envío del enlace es manual y la tienda gestiona el pago.

El formulario se agrega después del formulario clásico de compra. También se puede insertar con un bloque Shortcode de WordPress usando `[quoteflow_request product_id="123"]`, donde 123 es el ID de un producto compatible. La integración automática se verificó con el tema de bloques Twenty Twenty-Five y el bloque WooCommerce Add to Cart Form.

Las solicitudes admiten cantidades enteras de 1 a 100, correo y una nota de hasta 2000 caracteres. El servidor valida el producto, protege el formulario con nonce y campo antispam, y limita cada dirección de conexión a cinco solicitudes por ventana de diez minutos. Guarda una huella temporal derivada de la dirección y la ventana. Si la tienda usa un proxy, revisar su configuración antes de la beta para evitar compartir el límite entre compradores.

Las páginas de producto y las páginas con el shortcode envían encabezados de exclusión de caché. Configurar también la exclusión en cualquier caché externa que ignore estos encabezados.

## Persistencia y reintentos

La versión actualiza el esquema al cargar el plugin y conserva las ofertas anteriores. Las solicitudes se guardan localmente y se identifican por una clave de formulario única. Reenviar el mismo formulario con el mismo contenido conserva una sola solicitud.

Cada solicitud puede tener una única oferta. Un bloqueo por solicitud y un índice único evitan ofertas duplicadas. El enlace de esas ofertas se deriva de una clave privada de WordPress y un identificador de solicitud. Cambiar las claves de seguridad de WordPress afecta su recuperación desde la bandeja. Los enlaces ya compartidos conservan su validación por hash hasta vencer.

La conversión registra su intención antes de crear el pedido. Si el pedido se guardó y el vínculo se interrumpió, el plugin intenta recuperarlo por un marcador de WooCommerce. Si la búsqueda no identifica un único pedido válido, la conversión se detiene para revisión. El pago vuelve a validar el producto, el stock y el total.

## Pruebas

Copiar el plugin a la tienda descartable de M0 y activarlo. Ejecutar las pruebas de forma secuencial.

```sh
python3 tests/smoke.py RUTA_AL_COMPOSE_DE_M0
python3 tests/requests.py RUTA_AL_COMPOSE_DE_M0
```

Las pruebas cambian el modo de almacenamiento entre HPOS y clásico, habilitan el acceso público de la tienda local y crean datos ficticios. Usar exclusivamente la tienda de pruebas.

La primera cubre concurrencia, reintentos, recuperación de conversiones, falta de stock y expiración. La segunda recorre formularios públicos y de administración hasta el pago local, e incluye solicitudes y respuestas repetidas, permisos, validación y límite de solicitudes. La transferencia bancaria se confirma mediante una simulación local.

Quedan pendientes productos físicos y variables, impuestos, integración nativa con otros bloques, envío de correo, privacidad y borrado de datos, revisión visual y certificación con una pasarela en sandbox antes de la beta.

## Desarrollo y producción

El trabajo diario y las pruebas se realizan en `dev`. Los cambios se promueven a `main` mediante un pull request revisado cuando se aprueba una versión para producción. Las descargas estables se publican desde tags revisados de `main`. Los paquetes de desarrollo usan un canal beta o de desarrollo.

Después de clonar el repositorio, activar la protección local.

```sh
git switch dev
git config core.hooksPath .githooks
```

El hook local bloquea pushes a `main`, incluidos los intentos de borrado. La protección del servidor debe configurarse también en GitHub según las prestaciones del plan de la cuenta. En las aplicaciones desplegadas, la rama de producción se configura explícitamente como `main` y las pruebas usan entornos independientes.

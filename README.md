# QuoteFlow Free

Plugin WooCommerce en desarrollo para recibir solicitudes de cotización, preparar una oferta y convertir su aceptación en un pedido pagable.

## Recorrido disponible

1. El comprador solicita una cotización desde la ficha de un producto compatible o desde su carrito con varios productos.
2. El comerciante abre **WooCommerce → QuoteFlow**, revisa la solicitud e ingresa el total negociado de cada línea.
3. El comerciante pulsa **Send offer by email** para enviar la oferta al correo del comprador. También puede copiar y compartir el enlace privado.
4. El comprador acepta y continúa al pago nativo de WooCommerce.

La bandeja permite recuperar el enlace de una oferta creada desde una solicitud. Una respuesta repetida conserva la oferta original. El formulario independiente de creación de ofertas sigue disponible.

## Bandeja del comerciante

En **WooCommerce → QuoteFlow**, el campo **Buyer email or request number** permite buscar por correo completo, fragmento de correo o ID de solicitud, con o sin `#`. El filtro **Request status** se combina con la búsqueda y ofrece solicitudes sin oferta, ofertas pendientes de aceptación, vencidas, conversiones que requieren revisión, pedidos creados, rechazos y cancelaciones. **Clear filters** vuelve a la bandeja completa.

La lista muestra el número de resultados y de páginas, con 20 solicitudes por página y las más recientes primero. Los enlaces conservan la búsqueda y el estado. Una página fuera del rango se ajusta a la última página disponible; una consulta sin resultados muestra cero solicitudes. La selección de filtros y la navegación son operaciones de lectura.

Las ofertas vencidas se calculan por su fecha UTC dentro del estado pendiente de aceptación. Los pedidos creados, los rechazos y las cancelaciones conservan su propio estado aunque su fecha de oferta ya haya pasado. **Order created** indica que se creó el pedido; su estado de pago se consulta en WooCommerce.

Las solicitudes convertidas muestran **Open order**, cuando el operador tiene permiso para editar ese pedido. El enlace abre la pantalla nativa de WooCommerce, tanto con HPOS como con almacenamiento clásico. **View offer** conserva su función de recuperar el enlace privado desde una solicitud, con los filtros actuales. La bandeja lista solicitudes; las ofertas manuales sin solicitud mantienen su recorrido de creación y enlace compartido.

## Alcance de esta versión

Versión de desarrollo 0.10.0-dev. Admite entre 1 y 20 combinaciones diferentes de productos y opciones, con productos simples o variables físicos y virtuales por solicitud u oferta, compradores invitados, tiendas con impuestos desactivados y ofertas de 24 horas. Cada importe es el total negociado para la cantidad de su línea. El total de la oferta suma esos importes y el envío cotizado. El comerciante decide cuándo enviar la oferta y la tienda gestiona el pago.

El formulario se agrega después del formulario clásico de compra. También se puede insertar con un bloque Shortcode de WordPress usando `[quoteflow_request product_id="123"]`, donde 123 es el ID de un producto compatible. La integración automática de producto se verificó con el tema de bloques Twenty Twenty-Five y el bloque WooCommerce Add to Cart Form. El carrito incorpora un formulario para cotizar todos sus productos tanto con el bloque Cart como con el shortcode clásico de WooCommerce. Para otra página, insertar [quoteflow_request cart="yes"] en un bloque Shortcode.

Las solicitudes admiten cantidades enteras de 1 a 100 por producto, correo y una nota de hasta 2000 caracteres. El servidor valida el producto, protege el formulario con nonce y campo antispam, y limita cada dirección de conexión a cinco solicitudes por ventana de diez minutos. Guarda una huella temporal derivada de la dirección y la ventana. Si la tienda usa un proxy, revisar su configuración antes de la beta para evitar compartir el límite entre compradores.

Las páginas de producto y las páginas con el shortcode envían encabezados de exclusión de caché. Configurar también la exclusión en cualquier caché externa que ignore estos encabezados.

## Productos variables

La ficha de un producto variable muestra selectores propios en el formulario de cotización. El comprador completa todas las opciones antes de enviarlo. En el carrito, la cotización usa las variantes y opciones que WooCommerce ya tiene seleccionadas.

Las solicitudes, las ofertas y los pedidos guardan el identificador de la variante y sus atributos. Se admiten atributos personalizados y globales, y variantes configuradas para aceptar cualquier valor de un atributo. Cada combinación elegida aparece como una línea distinta.

Para crear una oferta manual, se puede indicar el ID de una variante con opciones fijas. Las variantes con opciones abiertas requieren una solicitud del comprador con la selección completa. El servidor valida las combinaciones, la disponibilidad y el stock. Si varias variantes comparten stock del producto principal, se compara la suma de sus cantidades.

Antes de aceptar y pagar se comprueban la variante y los atributos cotizados. Los pedidos recuperados tras una interrupción también deben conservar esos valores.

## Envío de productos físicos

Al responder una solicitud de productos físicos, el comerciante completa **Quoted shipping total** y **Shipping country**. El coste puede ser cero para envío gratuito. El país debe estar habilitado para venta y envío en WooCommerce. En una oferta creada sin solicitud, estos campos se completan junto al producto y su precio.

La página y el correo de la oferta muestran el envío y el país de destino. Antes de aceptar, el comprador completa la dirección correspondiente a ese país. El plugin valida los campos obligatorios según WooCommerce, el estado o provincia y el código postal. Por defecto, esa dirección se utiliza también para facturación. El comprador puede elegir una dirección de facturación distinta.

La primera aceptación guarda las direcciones y el pedido obtiene una línea **Quoted shipping** con el importe acordado. Los reintentos recuperan ese pedido y sus direcciones aceptadas. Un cambio de destino requiere una nueva oferta. Antes del pago se comprueban los importes y ambas direcciones, incluso cuando una alteración conserva el total general.

Este flujo usa un precio de envío fijado por el comerciante para el país cotizado. El cálculo de tarifas de transportistas en tiempo real queda pendiente. Las ofertas anteriores creadas con productos virtuales se interpretan con envío cero. Si cambia el requisito de entrega de un producto después de cotizar, se debe preparar otra oferta.

## Direcciones de entrega y facturación

Las ofertas de productos físicos muestran **Billing address option**, con **Same as delivery** como valor inicial. Para facturar a otro domicilio, elegir **Use a different billing address**, seleccionar el país de facturación y pulsar **Update address form** antes de introducir las direcciones. La actualización recarga los campos y descarta lo escrito; el texto del formulario lo indica. Este flujo funciona sin JavaScript y cambiar sus opciones conserva el estado de la oferta.

El país de facturación puede ser distinto del país de entrega, siempre que esté habilitado para venta en WooCommerce. Los campos y las provincias o estados se renderizan para el país de cada dirección. El servidor comprueba cada dirección por separado, incluidos sus campos obligatorios y el formato del código postal. El correo de facturación sigue siendo el correo al que se preparó la oferta. No se recoge un teléfono en este flujo.

El país de entrega y el importe cotizado de envío siguen fijos. Cambiar el país de facturación no modifica el destino de entrega ni el precio. Las direcciones separadas están disponibles en ofertas que requieren envío; las ofertas exclusivamente virtuales mantienen su recorrido actual.

Al aceptar se guardan dos snapshots. Reenviar el formulario o recuperar una conversión usa ambos valores aceptados. Una alteración de cualquiera de ellos en el pedido bloquea el pago y la recuperación para revisión. Las ofertas aceptadas antes de esta versión, con snapshot de facturación nulo, siguen usando la dirección de entrega para ambos fines. La exportación de privacidad incluye las dos direcciones guardadas.

## Rechazo y cancelación

El enlace privado permite al comprador aceptar la oferta o pulsar **Decline offer** para rechazarla. El rechazo queda registrado y la página muestra su estado. Para solicitar otra oferta, contactar con la tienda.

El comerciante puede abrir **View offer**, marcar la confirmación y pulsar **Cancel offer** antes de que la oferta se convierta en pedido. La bandeja muestra el rechazo o la cancelación, y se registra la fecha y el identificador del operador que cancela. Los rechazos mediante enlace privado se registran con actor invitado.

Aceptar, rechazar y cancelar comparten un bloqueo por oferta. Si dos decisiones llegan al mismo tiempo, se aplica la primera transición válida. Repetir la misma decisión conserva el resultado. Los pedidos y las conversiones pendientes de revisión se gestionan en WooCommerce antes de cambiar su estado. Las ofertas rechazadas o canceladas quedan excluidas de la aceptación y del envío por correo.

## Correo de ofertas

Después de crear una oferta, la pantalla muestra el destinatario y el botón **Send offer by email**. Para una oferta de la bandeja, abrir **View offer**. El envío usa el servicio de correo ya configurado en WordPress y el formato de WooCommerce, con líneas, importes, moneda, vencimiento y enlace privado.

Crear o recuperar una oferta conserva el estado de correo hasta que el operador solicite enviarla. La bandeja muestra el estado y la base registra los intentos y la fecha en que el servicio acepta el mensaje. La aceptación del servicio de correo permite verificar que se solicitó el envío. La recepción final depende del servidor del destinatario.

Un bloqueo por oferta y el número de intento impiden que un doble clic o un formulario antiguo repitan el envío. Si el servicio rechaza el mensaje, revisar la configuración y reintentar desde el formulario actualizado. Si una interrupción impide conocer el resultado, comprobar los registros del servicio y confirmar explícitamente el nuevo intento. Las ofertas vencidas o ya convertidas a pedidos quedan excluidas del envío.

Los registros anteriores comienzan con el correo pendiente de solicitud. La actualización conserva los datos y espera una acción explícita del operador.

## Privacidad: exportación y borrado

QuoteFlow se integra en **Herramientas → Exportar datos personales** y **Herramientas → Borrar datos personales** de WordPress. El administrador utiliza el flujo nativo de solicitudes y su confirmación, buscando por el correo exacto del comprador. También cubre compradores invitados. La integración sigue los [contratos de privacidad de WordPress](https://developer.wordpress.org/plugins/privacy/adding-the-personal-data-eraser-to-your-plugin/).

La exportación incluye solicitudes, notas, productos y opciones guardadas, importes, estados y fechas, historial de intentos de correo, direcciones aceptadas de entrega y facturación e identificador del pedido relacionado. Los enlaces privados, sus hashes, las claves de idempotencia y las huellas de límite de solicitudes quedan fuera del archivo exportado. WooCommerce exporta los datos propios del pedido mediante su integración independiente.

El borrado elimina las solicitudes sin oferta y las ofertas rechazadas, canceladas o vencidas que nunca iniciaron una conversión ni tienen un pedido. La oferta y su solicitud relacionada se eliminan juntas; su enlace privado deja de funcionar. Se eliminan también las notas, los snapshots, los hashes y los metadatos que contenían esas filas. Los lotes se procesan sin saltar registros al reducirse la tabla y los reintentos convergen sobre los datos restantes.

Se conservan las ofertas activas, las conversiones iniciadas, los registros vinculados a pedidos y los envíos de correo cuyo resultado sigue incierto. WordPress informa al administrador de esta retención. Cerrar una oferta activa permite repetir el borrado. Un envío incierto requiere revisar los registros del servicio y resolver su estado. Los pedidos se gestionan mediante las herramientas y la configuración de privacidad de WooCommerce; esta versión conserva el registro relacionado de QuoteFlow para mantener la recuperación y la validación del pago. El borrado de esos registros convertidos sigue pendiente de un flujo coordinado con la anonimización del pedido.

Los bloqueos de respuesta, envío y aceptación protegen el borrado frente a operaciones simultáneas. Si un registro está en uso, WordPress recibe un error que permite reintentar; las eliminaciones ya completadas se conservan. No se agregan endpoints públicos para exportar o borrar datos.

La tienda debe definir sus períodos de conservación y el tratamiento de las copias de seguridad, archivos exportados y registros del servicio de correo. Esta versión no incorpora borrado automático por antigüedad. Desactivar el plugin conserva sus tablas.

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
python3 tests/decisions.py RUTA_AL_COMPOSE_DE_M0
python3 tests/delivery.py RUTA_AL_COMPOSE_DE_M0
python3 tests/variations.py RUTA_AL_COMPOSE_DE_M0
python3 tests/privacy.py RUTA_AL_COMPOSE_DE_M0
python3 tests/billing.py RUTA_AL_COMPOSE_DE_M0
python3 tests/inbox.py RUTA_AL_COMPOSE_DE_M0
```

Las pruebas cambian el modo de almacenamiento entre HPOS y clásico, habilitan el acceso público de la tienda local y crean datos ficticios. Usar exclusivamente la tienda de pruebas.

La primera cubre concurrencia, reintentos, recuperación de conversiones, falta de stock y expiración. La segunda recorre formularios públicos y de administración hasta el pago local, e incluye solicitudes y respuestas repetidas, permisos, validación y límite de solicitudes. La tercera recorre un carrito de varios productos, precios por línea, conversión y pago con HPOS y almacenamiento clásico. Comprueba falta de stock en una línea, alteraciones de precios que mantienen el total, recuperación tras una interrupción y lectura de registros anteriores. También prueba el carrito de bloques y el clásico. La transferencia bancaria se confirma mediante una simulación local.

La prueba de correo instala temporalmente un capturador local que intercepta todos los envíos y se retira al finalizar. Verifica contenido, permisos, formulario inválido, envíos simultáneos, fallos y recuperación de resultados inciertos. Esta prueba verifica la integración de WordPress con el transporte capturado.

La prueba de decisiones cubre rechazo, cancelación, permisos, confirmación, bloqueo de correos y concurrencia frente a la aceptación en ambos almacenes de pedidos.

La prueba de entrega cubre solicitud de un producto físico, país cotizado, dirección obligatoria, importe de envío, recuperación de la conversión, correo capturado y protección del pago ante cambios. También cubre un pedido mixto con envío gratuito.

La prueba de variantes cubre formularios de producto y carrito, opciones abiertas, atributos globales, stock compartido o por variante, recuperación del pedido y pago en HPOS y almacenamiento clásico. También comprueba una variante física con envío cotizado.

Desde la versión 0.7, los campos de dirección usan la función de renderizado de WooCommerce corregida. La prueba comprueba ahora que el formulario de dirección se renderice completo antes de probar su envío.

La prueba de privacidad utiliza los hooks registrados y el AJAX nativo de WordPress. Comprueba exportación por correo, exclusión de claves privadas, permisos y nonces, eliminación de más de un lote, reintentos, lectura de registros anteriores y conservación de pedidos con HPOS y almacenamiento clásico. También comprueba bloqueos frente a respuestas, correos y decisiones, y borrados concurrentes. Intercepta temporalmente el correo para evitar entregas externas.

La prueba de facturación cubre selección de opciones sin modificar la oferta, formularios por país, entrega en EE. UU. con facturación en Reino Unido, países no permitidos, datos inválidos, aceptación concurrente, reintentos, recuperación y pago. Comprueba también la exportación de ambas direcciones y la compatibilidad con snapshots anteriores, con HPOS y almacenamiento clásico. La revisión visual sigue pendiente.

La prueba de bandeja cubre todos los estados, búsquedas por correo e ID, combinación de filtros, caracteres literales como `%` y `_`, páginas fuera del rango, más de una página y el límite exacto de 20 resultados. Comprueba los enlaces nativos de pedidos y la recuperación de una oferta desde una lista filtrada, con HPOS y almacenamiento clásico.

Quedan pendientes impuestos, tarifas de envío en tiempo real, integración nativa con otros bloques, entrega con un servicio real de correo, políticas de conservación y borrado coordinado de registros convertidos, revisión visual y certificación con una pasarela en sandbox antes de la beta.

## Desarrollo y producción

El trabajo diario y las pruebas se realizan en `dev`. Los cambios se promueven a `main` mediante un pull request revisado cuando se aprueba una versión para producción. Las descargas estables se publican desde tags revisados de `main`. Los paquetes de desarrollo usan un canal beta o de desarrollo.

Después de clonar el repositorio, activar la protección local.

```sh
git switch dev
git config core.hooksPath .githooks
```

El hook local bloquea pushes a `main`, incluidos los intentos de borrado. La protección del servidor debe configurarse también en GitHub según las prestaciones del plan de la cuenta. En las aplicaciones desplegadas, la rama de producción se configura explícitamente como `main` y las pruebas usan entornos independientes.

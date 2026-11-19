# QuoteFlow Free — 1.0.0-rc.1

Candidata para staging. Recibe solicitudes de cotización y convierte la aceptación de una oferta en un pedido pagable de WooCommerce. La aprobación de producción requiere completar [las tareas externas](docs/TUS_TAREAS.md).

## Instalación y uso

1. Instalar WooCommerce y configurar países, moneda, impuestos y métodos de pago.
2. Instalar el ZIP de QuoteFlow Free. Recargar los formularios que estaban abiertos antes de actualizar.
3. El formulario aparece en productos compatibles y carrito. También se puede usar `[quoteflow_request product_id="123"]` o `[quoteflow_request cart="yes"]`.
4. En **WooCommerce → QuoteFlow**, preparar una oferta desde una solicitud o desde el formulario manual.
5. Pulsar el botón de envío para solicitar el correo. El comprador abre su enlace privado y acepta para pagar en WooCommerce.

Las ofertas vencen en 24 horas. Hay filtros por correo, ID y estado, y una pestaña de ofertas manuales. Reintentar el mismo formulario conserva una sola oferta. Las cantidades son enteras entre 1 y 100, con hasta 20 combinaciones de productos y opciones.

## Precios, impuestos y direcciones

Los importes negociados de productos y envío son **totales finales con impuestos aplicables incluidos**, independientemente de cómo se muestren los precios del catálogo. Al aceptar, WooCommerce proporciona las tasas y se separan base e impuestos según su configuración de ubicación y redondeo. El total final cotizado permanece fijo.

Se admiten impuestos compuestos y clases de impuestos de productos. El envío usa la clase configurada en WooCommerce, incluida la herencia de clases. Cambiar las tasas, la ubicación fiscal, el redondeo o la configuración fiscal de un producto exige una nueva oferta. Las ofertas existentes conservan su modo original; activar impuestos después de crear una oferta sin impuestos exige reemplazarla.

Para productos físicos, el comerciante fija un importe de envío y un país de entrega. El comprador completa la dirección y puede usar otra de facturación, incluso en otro país permitido. Elegir las opciones y el país de facturación antes de completar los campos: actualizar el formulario borra los datos introducidos. Con impuestos activos, los productos virtuales solicitan dirección de facturación.

Los pedidos son de invitado. No se heredan exenciones fiscales del usuario conectado ni se verifica un número de IVA externo. Los plugins de exenciones, facturación, geolocalización fiscal y transportistas requieren validación adicional en staging. Las tarifas de envío en tiempo real quedan fuera de esta versión.

## Correo, enlaces y recuperación

El correo utiliza el transporte configurado en WordPress. Su aceptación por el transporte se registra; la recepción final se verifica con el proveedor y la casilla de destino. Una respuesta incierta exige revisar el registro antes de reintentar explícitamente.

Los enlaces son privados. QuoteFlow guarda su hash. Las ofertas derivadas de solicitudes y las ofertas manuales nuevas permiten recuperar su enlace con las claves de WordPress. Cambiar esas claves puede impedir su recuperación; los enlaces ya compartidos conservan su validación hasta vencer. Ofertas manuales antiguas con tokens aleatorios requieren su enlace original.

La conversión guarda su intención antes de crear el pedido y conserva un identificador único. Un reintento recupera ese pedido. Si no hay una coincidencia inequívoca, se detiene para revisión. Antes del pago se comprueban importes, impuestos, direcciones, opciones y stock. La aceptación y el pago usan la reserva atómica nativa de WooCommerce, respetando su configuración de gestión y tiempo de retención de stock. Las comprobaciones excluyen la reserva del propio pedido y las cancelaciones liberan la reserva mediante WooCommerce.

## Privacidad y datos

Se integran las herramientas nativas de exportación y borrado por correo, también para invitados. Se borran solicitudes sin oferta y ofertas cerradas o vencidas sin conversión. Las ofertas activas, las conversiones ambiguas y los envíos inciertos requieren revisión.

WooCommerce controla la anonimización de sus pedidos. QuoteFlow protege los pedidos pendientes, fallidos, en espera o en proceso en el borrado nativo. Después de anonimizar un pedido completado, cancelado o reembolsado, se borran su oferta y solicitud asociadas de forma atómica. Si un registro está ocupado, se agenda un reintento mediante WordPress cron y se deja una nota en el pedido. La tarea programada contiene solo el ID del registro. Un pedido anonimizado que se reabra requiere revisión; no se habilita su pago con datos borrados.

La tienda define los períodos de conservación y el tratamiento de respaldos, exportaciones y registros del correo. No hay borrado automático por antigüedad. Desactivar o desinstalar conserva las tablas; volver a instalar permite acceder a las herramientas de privacidad. No hay servicio de licencias ni telemetría externa.

## Compatibilidad y distribución

- Mínimos declarados: WordPress 7.0, PHP 8.3 y WooCommerce; baseline funcional WooCommerce 11.3.0.
- Pruebas completas en WordPress 7.1.4 / PHP 8.3, con HPOS y almacenamiento clásico.
- Comprobaciones adicionales de creación, conversión y reintento en WordPress 7.0 y PHP 8.4.
- Tablas InnoDB y bloqueos MySQL/MariaDB `GET_LOCK` requeridos. Validar el hosting antes del piloto.
- Traducciones incluidas: español de Argentina y español. El catálogo inicial usa español rioplatense.
- La revisión visual externa, la entrega de correo externo y la certificación de una pasarela sandbox son condiciones de lanzamiento pendientes del staging.

## Construcción y pruebas

```sh
python3 scripts/build.py
python3 scripts/setup-test-store.py --disposable
python3 scripts/verify.py compose.test.yaml --disposable
```

La construcción es reproducible y emite ZIP y SHA256. Excluye pruebas, credenciales ficticias y documentación interna. Las pruebas modifican exclusivamente la tienda M0 descartable de `127.0.0.1:18972`; no usar sus scripts contra producción. El bootstrap valida el nombre y la URL de una instalación existente antes de configurarla. Las imágenes y WP-CLI están fijados; WooCommerce está fijado a 11.3.0.

La CI valida sintaxis en PHP 8.3/8.4, construcción reproducible y el recorrido funcional con una tienda descartable. No despliega ni publica versiones. Revisar el resultado del workflow en GitHub antes de aprobar un release.

El trabajo se sube a `dev`. Tras completar las pruebas externas y revisar el PR, se promueve a `main` y se publica el tag estable. Activar la protección local con `git config core.hooksPath .githooks`.

Ver [estado de validación](docs/VALIDACION.md), [tareas del titular](docs/TUS_TAREAS.md), [procedimiento de lanzamiento](docs/LANZAMIENTO.md) y [revisión técnica](docs/SEGURIDAD.md).

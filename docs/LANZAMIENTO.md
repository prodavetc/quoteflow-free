# Lanzamiento y reversión

1. Confirmar que `docs/TUS_TAREAS.md` está aprobado y sin fallos abiertos que afecten datos, cobros o privacidad.
2. Revisar código y resultados de CI. Ejecutar la suite completa sobre una tienda descartable.
3. Probar instalación limpia y actualización sobre una copia de staging con datos anteriores. Conservar los enlaces, ofertas y pedidos existentes.
4. Cambiar de forma coherente Version, constante VERSION y Stable tag a `1.0.0`; actualizar changelog y regenerar traducciones.
5. Ejecutar `python3 scripts/build.py` dos veces. Deben coincidir los SHA256. Instalar el mismo ZIP en staging y comprobar su versión.
6. Aprobar el PR `dev` → `main`. Publicar el tag revisado y su ZIP/SHA256. Si se eligió WordPress.org, enviar a revisión y usar el SVN asignado según sus instrucciones; no publicar la candidata como estable.
7. Conservar el tag, ZIP, checksum, respaldo y registros de validación.

## Actualización

Hacer respaldo de archivos y base. Las migraciones son aditivas y conservan registros anteriores. Recargar formularios abiertos. Una oferta creada con impuestos desactivados no se transforma al activar impuestos: preparar otra oferta.

## Reversión

Desactivar QuoteFlow detiene sus formularios y rutas. Los pedidos siguen en WooCommerce, pero las operaciones de pedidos QuoteFlow requieren restaurar una versión compatible para mantener sus comprobaciones.

Restaurar el ZIP estable previo y el respaldo completo de base **solo en una ventana controlada sin pagos ni pedidos nuevos**. Si hubo actividad después del respaldo, reconciliar los pedidos y cobros antes de restaurar; no borrar actividad posterior. Evitar un downgrade improvisado de esquema o eliminar tablas.

Reportar incidencias sin correos personales, direcciones, claves de pedido ni enlaces de oferta. Una conversión ambigua se revisa por su marcador y pedido existente; no se recrea automáticamente.

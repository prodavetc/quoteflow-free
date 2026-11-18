# Flujo de ramas

- Desarrollar y subir los cambios a `dev`.
- Promover cambios a `main` mediante un pull request revisado y con pruebas satisfactorias, cuando el usuario autorice el pase a producción.
- Publicar versiones estables desde tags revisados de `main`. Los paquetes de desarrollo usan un canal beta o de desarrollo.
- Al preparar una copia local, ejecutar `git config core.hooksPath .githooks` para activar el bloqueo de push directo a `main`.

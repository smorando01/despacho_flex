# Guía de despliegue en cPanel

Esta guía resume los pasos necesarios para publicar el proyecto en un hosting con cPanel. Incluye una nota específica para el comportamiento de los archivos generados al comprimir el proyecto en macOS (carpeta `__MACOSX`).

## 1. Preparación local
1. **Revisa la estructura**. Comprueba que la carpeta contenga `index.html`, `historial.html`, `detalle_sesion.html` y el directorio `api/` con todos los endpoints PHP.
2. **Confirma `api/config.php`**. Ese archivo debe existir y contener la función `get_pdo()` con las credenciales que usarás en producción.

## 2. Crear el paquete ZIP
1. Desde tu máquina comprime todo el proyecto (por ejemplo, botón derecho → *Comprimir* en macOS o `zip -r despacho_flex.zip despacho_flex/` en Linux/Windows).
2. Guarda una copia del ZIP como respaldo local.

> 💡 **Nota sobre `__MACOSX`**: si el ZIP lo generas en macOS, al descomprimir en cPanel aparecerá una carpeta `__MACOSX`. Es un contenedor de metadatos que crea Finder. No contiene archivos del proyecto, puedes eliminarla sin problemas después de la extracción para mantener la carpeta limpia.

## 3. Subir y extraer en cPanel
1. Entra a **cPanel → File Manager** y navega hasta `public_html/` (o la carpeta donde se hospeda tu sitio).
2. Usa el botón **Upload** para subir el ZIP.
3. Selecciona el ZIP y elige **Extract**. Al terminar deberías ver la carpeta del proyecto (por ejemplo, `despacho_flex/`) y, si vienes de macOS, posiblemente la carpeta `__MACOSX`. Elimina `__MACOSX` y mueve el contenido de `despacho_flex/` a la raíz pública si quieres que el sitio quede directamente en `public_html/`.

La estructura final esperada es:
```
public_html/
  index.html
  historial.html
  detalle_sesion.html
  api/
    auth.php
    current_session.php
    ...
```

## 4. Configurar el secreto compartido
1. Define `API_SHARED_SECRET` en el entorno del servidor. Puedes hacerlo vía variable de entorno, `php.ini` o un archivo incluido con `define('API_SHARED_SECRET', 'tu_token_largo');` antes de cargar los endpoints.
2. Asegúrate de que PHP pueda iniciar sesión (`session_start`) porque los endpoints generan y validan tokens CSRF.

## 5. Configurar la base de datos
1. Edita `api/config.php` en el servidor para apuntar a la base de datos MySQL del hosting.
2. Prueba `api/current_session.php` con `curl` o desde el navegador para comprobar que responde con `success: true` y devuelve un `csrf_token`.

## 6. Validar el frontend
1. Carga `index.html`, introduce el token API cuando lo solicite y verifica que sincroniza la sesión mediante `api/current_session.php`.
2. Realiza un escaneo de prueba para confirmar que `scan.php` responde y que las métricas se actualizan.
3. Revisa `historial.html` y `detalle_sesion.html` para confirmar que pueden leer datos autenticados.

## 7. Comunicar el token al equipo
Comparte el valor de `API_SHARED_SECRET` con los operadores. El frontend lo guarda en `localStorage` y lo reutiliza en cada petición. Si cambias el token, los usuarios tendrán que volver a introducirlo cuando reciban un `401`.

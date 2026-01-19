# Generador de Correos Plesk

Aplicación web para crear cuentas de correo en Plesk de forma rápida y sencilla.

## Requisitos

- PHP 7.4+
- MySQL 5.7+ / MariaDB 10.3+
- Extensión cURL para PHP
- Acceso a API REST de Plesk

## Instalación

### 1. Clonar el repositorio

```bash
git clone <url-del-repo>
cd generador-correo
```

### 2. Configurar la base de datos

Crear la base de datos y ejecutar el schema:

```bash
mysql -u root -p crearcorreos_bd < database/schema.sql
```

### 3. Configurar credenciales

Copiar el archivo de configuración de ejemplo:

```bash
cp config/config.example.php config/config.php
```

Editar `config/config.php` con las credenciales reales.

### 4. Generar hash para usuario inicial

Ejecutar este comando para obtener el hash de la contraseña:

```bash
php -r "echo password_hash('TU_CONTRASEÑA', PASSWORD_DEFAULT) . PHP_EOL;"
```

Actualizar el INSERT en `database/schema.sql` con el hash generado.

### 5. Configurar el servidor web

El document root debe apuntar a la carpeta `public/`.

**Ejemplo para Apache (.htaccess ya incluido):**

```apache
<VirtualHost *:443>
    ServerName crearcorreos.ebone.es
    DocumentRoot /var/www/generador-correo/public
    
    <Directory /var/www/generador-correo/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

## Estructura del proyecto

```
generador-correo/
├── config/
│   ├── config.php          # Configuración (NO subir a git)
│   └── config.example.php  # Ejemplo de configuración
├── database/
│   └── schema.sql          # Esquema de base de datos
├── includes/
│   ├── init.php            # Inicialización
│   ├── Database.php        # Conexión PDO
│   ├── Auth.php            # Autenticación
│   ├── PleskApi.php        # API de Plesk
│   └── EmailLog.php        # Logs de correos
├── public/
│   ├── index.php           # Login
│   ├── dashboard.php       # Panel principal
│   ├── logout.php          # Cerrar sesión
│   └── assets/
│       └── css/style.css
└── README.md
```

## Uso

1. Acceder a `https://crearcorreos.ebone.es`
2. Iniciar sesión con las credenciales configuradas
3. Seleccionar dominio, introducir nombre de usuario y contraseña
4. Hacer clic en "Crear cuenta de correo"

## Seguridad

- Las credenciales de Plesk están en `config/config.php` (excluido de git)
- Las contraseñas de usuarios se almacenan hasheadas con bcrypt
- Protección contra SQL injection mediante PDO prepared statements
- Sesiones con tiempo de expiración configurable

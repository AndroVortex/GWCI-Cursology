<?php

declare(strict_types=1);

use App\Database\StoredProcedures;
use App\Integrations\CloudinaryClient;
use App\Integrations\PayPalClient;
use App\Models\Entities\AppUser;
use App\Models\Enums\ReportTarget;
use App\Repositories\CategoryRepository;
use App\Repositories\ChatRepository;
use App\Repositories\CommentRepository;
use App\Repositories\CourseRepository;
use App\Repositories\ExamAttemptRepository;
use App\Repositories\LearningRepository;
use App\Repositories\ModerationRepository;
use App\Repositories\PaymentMethodRepository;
use App\Repositories\PurchaseRepository;
use App\Repositories\ReactionRepository;
use App\Repositories\ReviewRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\CategoryService;
use App\Services\ChatService;
use App\Services\CheckoutService;
use App\Services\CommentService;
use App\Services\CourseService;
use App\Services\CourseValidator;
use App\Services\ExamAttemptService;
use App\Services\LearningService;
use App\Services\MediaService;
use App\Services\ModerationService;
use App\Services\PaymentMethodService;
use App\Services\PaymentProvider;
use App\Services\ProfileService;
use App\Services\ProfileValidator;
use App\Services\ReactionService;
use App\Services\RegistrationValidator;
use App\Services\ReviewService;
use App\Services\VerifiedMedia;

/**
 * Datos de demostración para presentar Cursology: 3 instructores, 6 alumnos, 5 cursos publicados
 * y 1 borrador, con compras, avance, exámenes, opiniones, comentarios, reacciones, conversaciones
 * y un comentario reportado. Todo pasa por los servicios y repositorios de la app, es decir, por
 * los SP. Las portadas y los videos son archivos públicos de la cuenta "demo" de Cloudinary: no
 * se sube nada. Lo usan scripts/seed_demo.php y tests/verify_maintenance_db.php.
 */
final class DemoSeeder
{
    public const DOMAIN = 'demo.cursology.local';

    private const MEDIA = 'https://res.cloudinary.com/demo/';
    private const COVER = 'c_fill,g_auto,w_1280,h_720';
    private const AVATAR = 'c_thumb,g_face,w_256,h_256';

    /** Videos públicos de la cuenta demo: ruta => [segundos, bytes]. */
    private const VIDEOS = [
        'dog.mp4' => [13, 9094354],
        'elephants.mp4' => [53, 41374583],
        'sea_turtle.mp4' => [15, 27932506],
        'ski_jump.mp4' => [5, 473500],
        'snow_horses.mp4' => [13, 35395483],
        'samples/elephants.mp4' => [49, 38855178],
        'samples/cld-sample-video.mp4' => [12, 35988643],
        'docs/walking_talking.mp4' => [15, 35823262],
        'kitten_fighting.mp4' => [10, 318849],
        'samples/dance-2.mp4' => [20, 22373558],
    ];

    /** Clave => datos del registro (el email es clave@DOMAIN), qué enseñar con la cuenta y, en instructores, perfil y foto. */
    public const ACCOUNTS = [
        'laura.mendez' => [
            'rol' => 'instructor', 'nombre' => 'Laura', 'apellido_paterno' => 'Méndez', 'apellido_materno' => 'Ruiz',
            'genero' => 'femenino', 'fecha_nacimiento' => '1988-04-12', 'foto' => 'woman.jpg',
            'descripcion' => 'Desarrolladora web con 10 años de experiencia en PHP y JavaScript. Me gusta enseñar con proyectos reales.',
            'muestra' => 'Instructora de PHP (completo + niveles) y JavaScript (por niveles): ventas, alumnos, comentarios y un mensaje sin leer.',
        ],
        'diego.herrera' => [
            'rol' => 'instructor', 'nombre' => 'Diego', 'apellido_paterno' => 'Herrera', 'apellido_materno' => 'Salas',
            'genero' => 'masculino', 'fecha_nacimiento' => '1985-11-02', 'foto' => 'samples/people/smiling-man.jpg',
            'descripcion' => 'Administrador de bases de datos. Llevo 12 años diseñando esquemas y optimizando consultas en MySQL.',
            'muestra' => 'Instructor de MySQL (gratis) y procedimientos almacenados (completo): opiniones y un mensaje sin leer.',
        ],
        'sofia.ramirez' => [
            'rol' => 'instructor', 'nombre' => 'Sofía', 'apellido_paterno' => 'Ramírez', 'apellido_materno' => 'Torres',
            'genero' => 'femenino', 'fecha_nacimiento' => '1992-06-25', 'foto' => 'lady.jpg',
            'descripcion' => 'Diseñadora UX/UI. Ayudo a equipos a convertir ideas en prototipos que la gente entiende a la primera.',
            'muestra' => 'Instructora de Diseño de interfaces y de un curso en borrador (Figma) sin publicar.',
        ],
        'ana.lopez' => [
            'rol' => 'student', 'nombre' => 'Ana', 'apellido_paterno' => 'López', 'apellido_materno' => 'Herrera',
            'genero' => 'femenino', 'fecha_nacimiento' => '2001-09-03',
            'muestra' => 'Compró PHP completo y terminó PHP y MySQL: puede generar 2 certificados. Opina, comenta, reporta y escribe a Laura.',
        ],
        'bruno.garcia' => [
            'rol' => 'student', 'nombre' => 'Bruno', 'apellido_paterno' => 'García', 'apellido_materno' => 'Peña',
            'genero' => 'masculino', 'fecha_nacimiento' => '1999-01-17',
            'muestra' => 'Avance parcial en PHP (nivel gratis) y JavaScript (compró el nivel 2). Su pregunta tiene respuesta de Laura.',
        ],
        'carla.nunez' => [
            'rol' => 'student', 'nombre' => 'Carla', 'apellido_paterno' => 'Núñez', 'apellido_materno' => 'Vega',
            'genero' => 'femenino', 'fecha_nacimiento' => '1997-03-28',
            'muestra' => 'Compró y terminó Procedimientos almacenados (certificado disponible) y le escribió a Diego.',
        ],
        'daniel.ortiz' => [
            'rol' => 'student', 'nombre' => 'Daniel', 'apellido_paterno' => 'Ortiz', 'apellido_materno' => 'Luna',
            'genero' => 'masculino', 'fecha_nacimiento' => '2000-12-09',
            'muestra' => 'Terminó MySQL gratis (opinión de 3 estrellas y certificado disponible) y empezó JavaScript.',
        ],
        'elena.castro' => [
            'rol' => 'student', 'nombre' => 'Elena', 'apellido_paterno' => 'Castro', 'apellido_materno' => 'Mora',
            'genero' => 'femenino', 'fecha_nacimiento' => '1998-07-14',
            'muestra' => 'Compró solo el nivel 1 de Diseño de interfaces; tiene una respuesta de Sofía sin leer.',
        ],
        'fernando.rios' => [
            'rol' => 'student', 'nombre' => 'Fernando', 'apellido_paterno' => 'Ríos', 'apellido_materno' => 'Campos',
            'genero' => 'masculino', 'fecha_nacimiento' => '2002-02-21',
            'muestra' => 'Reprobó el examen de PHP y publicó un comentario de spam que está en la cola de moderación.',
        ],
    ];

    /** Categorías que usan los cursos (se crean si no existen). */
    private const CATEGORIES = [
        'Programación web' => 'Sitios y aplicaciones web: del servidor al navegador.',
        'Bases de datos' => 'Diseño, consultas y programación en bases de datos relacionales.',
        'Diseño' => 'Diseño de interfaces, experiencia de usuario y prototipos.',
    ];

    /**
     * Clave => curso. Los capítulos llevan una clave para la actividad; los de examen traen
     * minimo y preguntas [pregunta, modo, [[opción, correcta], ...]].
     */
    private const COURSES = [
        'php' => [
            'instructor' => 'laura.mendez', 'publicado' => true,
            'titulo' => 'PHP desde cero: tu primer sitio dinámico',
            'descripcion' => 'Aprende PHP paso a paso: sintaxis, programación orientada a objetos y cómo conectar tu sitio con MySQL usando PDO y procedimientos almacenados.',
            'precio_modo' => 'paquete', 'precio' => '499', 'categorias' => ['Programación web', 'Bases de datos'],
            'portada' => 'samples/landscapes/architecture-signs.jpg', 'video' => 'samples/cld-sample-video.mp4',
            'niveles' => [
                ['titulo' => 'Fundamentos', 'descripcion' => 'Instalación, variables, tipos de datos y tu primer script.', 'costo' => '0', 'video' => 'dog.mp4', 'capitulos' => [
                    ['clave' => 'php.instalacion', 'titulo' => 'Instalación y primer script', 'video' => 'ski_jump.mp4',
                        'texto' => ['Resumen', 'Instala Laragon (o XAMPP), crea un archivo index.php con echo "Hola mundo"; y ábrelo en http://localhost. Activa la extensión pdo_mysql desde el inicio.']],
                    ['clave' => 'php.variables', 'titulo' => 'Variables y tipos de datos', 'video' => 'snow_horses.mp4',
                        'enlace' => ['Tipos en el manual de PHP', 'https://www.php.net/manual/es/language.types.php']],
                    ['clave' => 'php.examen', 'titulo' => 'Examen: fundamentos', 'minimo' => '70', 'preguntas' => [
                        ['¿Dónde se ejecuta el código PHP?', 'unica', [['En el servidor', true], ['En el navegador', false], ['En la base de datos', false]]],
                        ['¿Cuáles de estos son tipos escalares en PHP?', 'multiple', [['int', true], ['string', true], ['bool', true], ['array', false]]],
                        ['¿Con qué símbolo empieza el nombre de una variable?', 'unica', [['$', true], ['@', false], ['#', false]]],
                    ]],
                ]],
                ['titulo' => 'Programación orientada a objetos', 'descripcion' => 'Clases, objetos, herencia e interfaces con ejemplos reales.', 'costo' => '249', 'video' => 'kitten_fighting.mp4', 'capitulos' => [
                    ['clave' => 'php.clases', 'titulo' => 'Clases y objetos', 'video' => 'docs/walking_talking.mp4',
                        'enlace' => ['Clases y objetos en el manual', 'https://www.php.net/manual/es/language.oop5.php']],
                    ['clave' => 'php.herencia', 'titulo' => 'Herencia e interfaces', 'video' => 'sea_turtle.mp4'],
                ]],
                ['titulo' => 'PHP con MySQL', 'descripcion' => 'Conexión con PDO, consultas preparadas y procedimientos almacenados.', 'costo' => '299', 'video' => 'dog.mp4', 'capitulos' => [
                    ['clave' => 'php.pdo', 'titulo' => 'Conexión con PDO', 'video' => 'samples/elephants.mp4'],
                    ['clave' => 'php.sp', 'titulo' => 'Llamar procedimientos almacenados', 'video' => 'ski_jump.mp4',
                        'texto' => ['Ejemplo', 'Con PDO: $stmt = $pdo->prepare("CALL sp_catalog_search(?, ?)"); $stmt->execute([$texto, $pagina]); y recorre los result sets con nextRowset().']],
                ]],
            ],
        ],
        'js' => [
            'instructor' => 'laura.mendez', 'publicado' => true,
            'titulo' => 'JavaScript moderno para la web',
            'descripcion' => 'Lo esencial de JavaScript moderno y cómo usarlo para dar vida a tus páginas: DOM, eventos y formularios.',
            'precio_modo' => 'niveles', 'precio' => '', 'categorias' => ['Programación web'],
            'portada' => 'samples/landscapes/girl-urban-view.jpg', 'video' => 'samples/dance-2.mp4',
            'niveles' => [
                ['titulo' => 'Lo esencial', 'descripcion' => 'Variables, funciones y arreglos con la sintaxis actual.', 'costo' => '0', 'video' => 'kitten_fighting.mp4', 'capitulos' => [
                    ['clave' => 'js.variables', 'titulo' => 'let, const y tipos', 'video' => 'samples/dance-2.mp4'],
                    ['clave' => 'js.funciones', 'titulo' => 'Funciones y funciones flecha', 'video' => 'dog.mp4',
                        'texto' => ['Para recordar', 'Una función flecha no tiene su propio this: úsala en callbacks y prefiere function para los métodos.']],
                ]],
                ['titulo' => 'DOM y eventos', 'descripcion' => 'Selecciona, crea y modifica elementos, y responde a lo que hace el usuario.', 'costo' => '199', 'video' => 'ski_jump.mp4', 'capitulos' => [
                    ['clave' => 'js.dom', 'titulo' => 'Seleccionar y modificar elementos', 'video' => 'sea_turtle.mp4',
                        'enlace' => ['Introducción al DOM (MDN)', 'https://developer.mozilla.org/es/docs/Web/API/Document_Object_Model/Introduction']],
                    ['clave' => 'js.eventos', 'titulo' => 'Eventos y formularios', 'video' => 'snow_horses.mp4'],
                ]],
            ],
        ],
        'mysql' => [
            'instructor' => 'diego.herrera', 'publicado' => true,
            'titulo' => 'MySQL práctico',
            'descripcion' => 'Un curso gratis para escribir consultas con confianza: filtros, agrupaciones, llaves y JOIN.',
            'precio_modo' => 'gratis', 'precio' => '', 'categorias' => ['Bases de datos'],
            'portada' => 'coffee_cup.jpg', 'video' => 'elephants.mp4',
            'niveles' => [
                ['titulo' => 'Consultas básicas', 'descripcion' => 'SELECT, WHERE, ORDER BY y GROUP BY.', 'costo' => '0', 'video' => 'dog.mp4', 'capitulos' => [
                    ['clave' => 'mysql.select', 'titulo' => 'SELECT, WHERE y ORDER BY', 'video' => 'sea_turtle.mp4'],
                    ['clave' => 'mysql.group', 'titulo' => 'Agrupar con GROUP BY', 'video' => 'kitten_fighting.mp4',
                        'texto' => ['Regla de oro', 'Toda columna del SELECT que no esté en una función de agregado (COUNT, SUM, AVG...) debe ir en el GROUP BY.']],
                ]],
                ['titulo' => 'Relaciones y JOIN', 'descripcion' => 'Llaves primarias y foráneas, y cómo combinar tablas.', 'costo' => '0', 'video' => 'ski_jump.mp4', 'capitulos' => [
                    ['clave' => 'mysql.llaves', 'titulo' => 'Llaves primarias y foráneas', 'video' => 'snow_horses.mp4'],
                    ['clave' => 'mysql.join', 'titulo' => 'INNER JOIN y LEFT JOIN', 'video' => 'dog.mp4',
                        'enlace' => ['JOIN en el manual de MySQL', 'https://dev.mysql.com/doc/refman/8.4/en/join.html']],
                    ['clave' => 'mysql.examen', 'titulo' => 'Examen: relaciones', 'minimo' => '60', 'preguntas' => [
                        ['¿Qué JOIN conserva las filas de la tabla izquierda aunque no tengan coincidencia?', 'unica', [['LEFT JOIN', true], ['INNER JOIN', false], ['CROSS JOIN', false]]],
                        ['¿Qué garantiza una llave foránea?', 'unica', [['Que el valor exista en la tabla referenciada', true], ['Que el valor sea único', false], ['Que la columna no acepte NULL', false]]],
                    ]],
                ]],
            ],
        ],
        'sp' => [
            'instructor' => 'diego.herrera', 'publicado' => true,
            'titulo' => 'Procedimientos almacenados en MySQL',
            'descripcion' => 'Mueve la lógica de datos a la base: parámetros, transacciones, manejo de errores con SIGNAL y buenas prácticas.',
            'precio_modo' => 'paquete', 'precio' => '349', 'categorias' => ['Bases de datos'],
            'portada' => 'samples/landscapes/nature-mountains.jpg', 'video' => 'samples/elephants.mp4',
            'niveles' => [
                ['titulo' => 'Primeros procedimientos', 'descripcion' => 'Crear, llamar y parametrizar procedimientos.', 'costo' => '199', 'video' => 'kitten_fighting.mp4', 'capitulos' => [
                    ['clave' => 'sp.intro', 'titulo' => 'Qué es un procedimiento almacenado', 'video' => 'docs/walking_talking.mp4'],
                    ['clave' => 'sp.parametros', 'titulo' => 'Parámetros IN y OUT', 'video' => 'ski_jump.mp4',
                        'texto' => ['Recuerda', 'Usa DELIMITER $$ en el cliente mysql para que el ; dentro del cuerpo no termine el CREATE PROCEDURE.']],
                ]],
                ['titulo' => 'Transacciones y errores', 'descripcion' => 'START TRANSACTION, COMMIT, ROLLBACK, handlers y SIGNAL.', 'costo' => '199', 'video' => 'dog.mp4', 'capitulos' => [
                    ['clave' => 'sp.transacciones', 'titulo' => 'START TRANSACTION, COMMIT y ROLLBACK', 'video' => 'sea_turtle.mp4'],
                    ['clave' => 'sp.errores', 'titulo' => 'Manejo de errores con SIGNAL', 'video' => 'snow_horses.mp4',
                        'enlace' => ['SIGNAL en el manual de MySQL', 'https://dev.mysql.com/doc/refman/8.4/en/signal.html']],
                    ['clave' => 'sp.examen', 'titulo' => 'Examen final', 'minimo' => '70', 'preguntas' => [
                        ['¿Qué hace un EXIT HANDLER FOR SQLEXCEPTION que ejecuta ROLLBACK?', 'unica', [['Deshace la transacción si ocurre un error', true], ['Ignora el error y sigue', false], ['Repite la sentencia que falló', false]]],
                        ['¿Con qué se lanza un error de negocio propio?', 'unica', [["SIGNAL SQLSTATE '45000'", true], ['RAISE ERROR', false], ['THROW', false]]],
                    ]],
                ]],
            ],
        ],
        'diseno' => [
            'instructor' => 'sofia.ramirez', 'publicado' => true,
            'titulo' => 'Diseño de interfaces: de la idea al prototipo',
            'descripcion' => 'Principios visuales, color, tipografía y prototipado para diseñar interfaces claras y probarlas con usuarios.',
            'precio_modo' => 'paquete', 'precio' => '299', 'categorias' => ['Diseño'],
            'portada' => 'yellow_tulip.jpg', 'video' => 'sea_turtle.mp4',
            'niveles' => [
                ['titulo' => 'Principios visuales', 'descripcion' => 'Jerarquía, contraste, color y tipografía.', 'costo' => '149', 'video' => 'dog.mp4', 'capitulos' => [
                    ['clave' => 'diseno.jerarquia', 'titulo' => 'Jerarquía y contraste', 'video' => 'samples/dance-2.mp4'],
                    ['clave' => 'diseno.color', 'titulo' => 'Color y tipografía', 'video' => 'kitten_fighting.mp4',
                        'texto' => ['Tip', 'Limita la paleta a un color principal, uno de acento y grises; revisa el contraste de texto con una herramienta WCAG.']],
                ]],
                ['titulo' => 'Prototipado', 'descripcion' => 'Wireframes, prototipos navegables y pruebas con usuarios.', 'costo' => '199', 'video' => 'ski_jump.mp4', 'capitulos' => [
                    ['clave' => 'diseno.wireframes', 'titulo' => 'Wireframes de baja fidelidad', 'video' => 'snow_horses.mp4'],
                    ['clave' => 'diseno.pruebas', 'titulo' => 'Pruebas con usuarios', 'video' => 'docs/walking_talking.mp4',
                        'enlace' => ['Pruebas de usabilidad (Nielsen Norman Group)', 'https://www.nngroup.com/articles/usability-testing-101/']],
                ]],
            ],
        ],
        'figma' => [
            'instructor' => 'sofia.ramirez', 'publicado' => false,
            'titulo' => 'Figma para principiantes',
            'descripcion' => 'Borrador: primeros pasos en Figma para crear tus propios prototipos.',
            'precio_modo' => 'gratis', 'precio' => '', 'categorias' => ['Diseño'],
            'portada' => 'samples/ecommerce/accessories-bag.jpg', 'video' => 'kitten_fighting.mp4',
            'niveles' => [
                ['titulo' => 'Primeros pasos', 'descripcion' => 'La interfaz de Figma y tus primeros marcos.', 'costo' => '0', 'video' => 'dog.mp4', 'capitulos' => [
                    ['clave' => 'figma.interfaz', 'titulo' => 'La interfaz de Figma', 'video' => 'ski_jump.mp4'],
                ]],
            ],
        ],
    ];

    private readonly UserRepository $users;
    private readonly AuthService $auth;
    private readonly ProfileService $profiles;
    private readonly CategoryService $categories;
    private readonly CourseRepository $courses;
    private readonly CourseValidator $validator;
    private readonly CourseService $courseService;
    private readonly PaymentMethodService $methods;
    private readonly CheckoutService $checkout;
    private readonly LearningService $learning;
    private readonly ExamAttemptService $exams;
    private readonly ReviewService $reviews;
    private readonly CommentService $comments;
    private readonly ReactionService $reactions;
    private readonly ChatService $chats;
    private readonly ModerationService $moderation;

    /** @var array<string, AppUser> */
    private array $accounts = [];
    /** @var array<string, string> clave del curso => id */
    private array $courseIds = [];
    /** @var array<string, list<string>> clave del curso => ids de sus niveles */
    private array $levelIds = [];
    /** @var array<string, list<string>> clave del curso => claves de sus capítulos, en orden */
    private array $courseChapters = [];
    /** @var array<string, string> clave del capítulo => id */
    private array $chapterIds = [];
    /** @var array<string, list<array{correct: list<string>, wrong: string}>> clave del examen => opciones por pregunta */
    private array $examAnswers = [];
    /** @var array<string, string> nombre => id de las reacciones activas */
    private array $reactionIds = [];

    public function __construct(StoredProcedures $procedures)
    {
        $noCloudinary = new CloudinaryClient('', '', '');
        $media = new MediaService($noCloudinary);
        $paypal = new PayPalClient('', '');

        $this->users = new UserRepository($procedures);
        $this->auth = new AuthService($this->users, new RegistrationValidator(), $noCloudinary);
        $this->profiles = new ProfileService($this->users, new ProfileValidator(), $noCloudinary);
        $this->categories = new CategoryService(new CategoryRepository($procedures));
        $this->courses = new CourseRepository($procedures);
        $this->validator = new CourseValidator();
        $this->courseService = new CourseService($this->courses, $this->validator, $media);
        $this->methods = new PaymentMethodService(new PaymentMethodRepository($procedures), $paypal);
        $this->checkout = new CheckoutService(new PurchaseRepository($procedures), $this->methods, $paypal);
        $this->learning = new LearningService(new LearningRepository($procedures), $media);
        $this->exams = new ExamAttemptService(new ExamAttemptRepository($procedures));
        $this->reviews = new ReviewService(new ReviewRepository($procedures));
        $this->comments = new CommentService(new CommentRepository($procedures));
        $this->reactions = new ReactionService(new ReactionRepository($procedures));
        $this->chats = new ChatService(new ChatRepository($procedures));
        $this->moderation = new ModerationService(new ModerationRepository($procedures));
    }

    public static function email(string $key): string
    {
        return $key . '@' . self::DOMAIN;
    }

    public function alreadySeeded(): bool
    {
        foreach (array_keys(self::ACCOUNTS) as $key) {
            if ($this->users->findForLogin(self::email($key)) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Carga todo. Si algo falla a la mitad, lo ya creado se quita con
     * php scripts/purge_test_data.php --demo --apply.
     *
     * @return list<string> avisos de lo que se cambió fuera de los datos de demostración
     */
    public function run(AppUser $admin, string $password): array
    {
        $notes = [];
        if (!in_array(PaymentProvider::SimulatedCard, $this->methods->available(), true)) {
            $this->methods->setActive($admin, ['codigo' => 'simulated_card', 'activa' => true]);
            $notes[] = 'Se activó la tarjeta simulada (Formas de pago del admin): las compras de la demostración la usan.';
        }
        $added = $this->reactions->addDefaults($admin);
        if ($added !== []) {
            $notes[] = sprintf('Se agregaron %d reacciones sugeridas al catálogo.', count($added));
        }
        foreach ($this->reactions->active() as $reaction) {
            $this->reactionIds[$reaction->reactionName()] = $reaction->id();
        }
        $categoryIds = $this->ensureCategories($admin, $notes);

        foreach (self::ACCOUNTS as $key => $account) {
            $this->accounts[$key] = $this->createAccount($key, $account, $password);
        }
        foreach (self::COURSES as $key => $course) {
            $this->createCourse($key, $course, $categoryIds);
        }
        $this->activity();

        return $notes;
    }

    /**
     * @param list<string> $notes
     * @return array<string, string> nombre => id
     */
    private function ensureCategories(AppUser $admin, array &$notes): array
    {
        $existing = [];
        foreach ($this->categories->all() as $category) {
            $existing[mb_strtolower($category->name())] = $category;
        }

        $ids = [];
        foreach (self::CATEGORIES as $name => $description) {
            $category = $existing[mb_strtolower($name)] ?? null;
            if ($category === null) {
                $category = $this->categories->create($admin, ['nombre' => $name, 'descripcion' => $description]);
                $notes[] = sprintf('Se creó la categoría "%s".', $name);
            } elseif (!$category->active()) {
                $category = $this->categories->update($admin, $category->id(), ['activa' => true]);
                $notes[] = sprintf('Se reactivó la categoría "%s".', $name);
            }
            $ids[$name] = $category->id();
        }

        return $ids;
    }

    /** @param array<string, string> $account */
    private function createAccount(string $key, array $account, string $password): AppUser
    {
        $fields = [
            'rol' => $account['rol'], 'nombre' => $account['nombre'], 'apellido_paterno' => $account['apellido_paterno'],
            'apellido_materno' => $account['apellido_materno'], 'genero' => $account['genero'],
            'fecha_nacimiento' => $account['fecha_nacimiento'], 'email' => self::email($key),
        ];
        $user = $this->auth->register($fields + ['password' => $password], null);

        if (isset($account['descripcion'])) {
            $user = $this->profiles->updateProfile($user, $fields + ['descripcion' => $account['descripcion']]);
        }
        if (isset($account['foto'])) {
            $user = $this->users->updateAvatar($user->id(), self::image($account['foto'], self::AVATAR));
        }

        return $user;
    }

    /**
     * @param array<string, mixed> $spec
     * @param array<string, string> $categoryIds
     */
    private function createCourse(string $key, array $spec, array $categoryIds): void
    {
        $instructor = $this->accounts[$spec['instructor']];
        $course = $this->courses->create($instructor->id(), $this->validator->validateCourse([
            'titulo' => $spec['titulo'], 'descripcion' => $spec['descripcion'], 'certificador' => '',
            'precio_modo' => $spec['precio_modo'], 'precio' => $spec['precio'],
            'categorias' => array_map(static fn (string $name): string => $categoryIds[$name], $spec['categorias']),
            'portada' => [], 'video' => [],
        ], true), self::image($spec['portada'], self::COVER), self::video($spec['video']));
        $this->courseIds[$key] = $course->id();
        $this->courseChapters[$key] = [];

        foreach ($spec['niveles'] as $levelSpec) {
            $level = $this->courses->createLevel($instructor->id(), $course->id(), $this->validator->validateLevel([
                'titulo' => $levelSpec['titulo'], 'descripcion' => $levelSpec['descripcion'], 'costo' => $levelSpec['costo'], 'video' => [],
            ], true), self::video($levelSpec['video']));
            $this->levelIds[$key][] = $level->id();

            foreach ($levelSpec['capitulos'] as $chapterSpec) {
                $this->createChapter($instructor, $level->id(), $chapterSpec);
                $this->courseChapters[$key][] = $chapterSpec['clave'];
            }
        }

        if ($spec['publicado']) {
            $this->courseService->publish($instructor, $course->id());
        }
    }

    /** @param array<string, mixed> $spec */
    private function createChapter(AppUser $instructor, string $levelId, array $spec): void
    {
        $isExam = isset($spec['preguntas']);
        $chapter = $this->courses->createChapter($instructor->id(), $levelId, $this->validator->validateChapter(
            ['titulo' => $spec['titulo'], 'tipo' => $isExam ? 'examen' : 'video'] + ($isExam ? ['minimo' => $spec['minimo']] : []),
            true,
        ));
        $this->chapterIds[$spec['clave']] = $chapter->id();

        if ($isExam) {
            foreach ($spec['preguntas'] as [$question, $mode, $options]) {
                [, $answers] = $this->courses->createQuestion($instructor->id(), $chapter->id(), $this->validator->validateQuestion([
                    'pregunta' => $question, 'modo' => $mode,
                    'respuestas' => array_map(static fn (array $option): array => ['texto' => $option[0], 'correcta' => $option[1]], $options),
                ]));
                $correct = array_values(array_filter($answers, static fn ($answer): bool => $answer->isCorrect()));
                $wrong = array_values(array_filter($answers, static fn ($answer): bool => !$answer->isCorrect()));
                $this->examAnswers[$spec['clave']][] = [
                    'correct' => array_map(static fn ($answer): string => $answer->id(), $correct),
                    'wrong' => $wrong[0]->id(),
                ];
            }

            return;
        }

        $this->courses->addContent($instructor->id(), $chapter->id(), $this->validator->validateContent(['tipo' => 'video_principal', 'archivo' => []]), $spec['titulo'], self::verifiedVideo($spec['video']));
        if (isset($spec['texto'])) {
            [$title, $text] = $spec['texto'];
            $this->courses->addContent($instructor->id(), $chapter->id(), $this->validator->validateContent(['tipo' => 'texto', 'titulo' => $title, 'texto' => $text]), $title, null);
        }
        if (isset($spec['enlace'])) {
            [$title, $url] = $spec['enlace'];
            $this->courses->addContent($instructor->id(), $chapter->id(), $this->validator->validateContent(['tipo' => 'enlace', 'titulo' => $title, 'url' => $url]), $title, null);
        }
    }

    /** Compras, avance, opiniones, comentarios, reacciones, conversaciones y el reporte. */
    private function activity(): void
    {
        $u = $this->accounts;

        // Inscripciones y compras con la tarjeta simulada.
        $this->buy('ana.lopez', 'php');
        $this->checkout->enrollFree($u['ana.lopez'], ['curso' => $this->courseIds['mysql']]);
        $this->checkout->enrollFree($u['ana.lopez'], ['curso' => $this->courseIds['js']]);
        $this->checkout->enrollFree($u['bruno.garcia'], ['curso' => $this->courseIds['php']]);
        $this->checkout->enrollFree($u['bruno.garcia'], ['curso' => $this->courseIds['js']]);
        $this->buy('bruno.garcia', 'js', 1);
        $this->buy('carla.nunez', 'sp');
        $this->checkout->enrollFree($u['daniel.ortiz'], ['curso' => $this->courseIds['mysql']]);
        $this->checkout->enrollFree($u['daniel.ortiz'], ['curso' => $this->courseIds['js']]);
        $this->buy('elena.castro', 'diseno', 0);
        $this->checkout->enrollFree($u['fernando.rios'], ['curso' => $this->courseIds['php']]);
        $this->buy('fernando.rios', 'php', 1);

        // Avance: cursos terminados (con el examen aprobado) y avances parciales.
        $this->completeCourse('ana.lopez', 'php');
        $this->completeCourse('ana.lopez', 'mysql');
        $this->completeCourse('carla.nunez', 'sp');
        $this->completeCourse('daniel.ortiz', 'mysql');
        $this->watch('ana.lopez', 'js.variables', 8);
        $this->watch('bruno.garcia', 'php.instalacion');
        $this->watch('bruno.garcia', 'php.variables', 6);
        $this->watch('bruno.garcia', 'js.variables');
        $this->watch('bruno.garcia', 'js.dom', 5);
        $this->watch('daniel.ortiz', 'js.variables', 12);
        $this->watch('elena.castro', 'diseno.jerarquia');
        $this->watch('elena.castro', 'diseno.color', 4);
        $this->watch('fernando.rios', 'php.instalacion');
        $this->watch('fernando.rios', 'php.variables');
        $this->failExam('fernando.rios', 'php.examen');

        // Calificaciones y opiniones (solo de cursos terminados).
        $anaPhp = $this->review('ana.lopez', 'php', 5, 'Explicaciones claras y ejemplos que sí se usan en proyectos reales. El examen ayuda mucho a repasar.');
        $this->review('ana.lopez', 'mysql', 4, 'Muy buen repaso de consultas. Me hubiera gustado un poco más de práctica con JOIN.');
        $this->review('carla.nunez', 'sp', 5, 'Por fin entendí las transacciones y el manejo de errores. Lo aplico ya en mi trabajo.');
        $danielMysql = $this->review('daniel.ortiz', 'mysql', 3, 'Buen contenido, pero los videos son cortos y faltan ejercicios para practicar.');
        $this->reactions->reactToOpinion($u['bruno.garcia'], $anaPhp, ['reaccion' => $this->reaction('Me sirvió')]);
        $this->reactions->reactToOpinion($u['carla.nunez'], $anaPhp, ['reaccion' => $this->reaction('Me gusta')]);
        $this->reactions->reactToOpinion($u['elena.castro'], $danielMysql, ['reaccion' => $this->reaction('Me gusta')]);

        // Comentarios por capítulo, respuestas del instructor y reacciones.
        $question = $this->comment('bruno.garcia', 'php.variables', '¿Cuál es la diferencia entre usar comillas simples y dobles en un texto?');
        $answer = $this->comment('laura.mendez', 'php.variables', 'Con comillas dobles PHP reemplaza las variables y entiende secuencias como \n; con simples, el texto queda tal cual. Para textos fijos, usa simples.', $question);
        $this->comment('ana.lopez', 'php.variables', '¡Tenía la misma duda, gracias!', $question);
        $this->comments->react($u['bruno.garcia'], $answer, ['reaccion' => $this->reaction('Me sirvió')]);
        $this->comments->react($u['ana.lopez'], $answer, ['reaccion' => $this->reaction('Me gusta')]);
        $tip = $this->comment('ana.lopez', 'php.instalacion', 'Me funcionó a la primera con Laragon. Recomiendo activar pdo_mysql desde el inicio para no batallar en el nivel 3.');
        $this->comments->react($u['fernando.rios'], $tip, ['reaccion' => $this->reaction('Me encanta')]);
        $spam = $this->comment('fernando.rios', 'php.instalacion', 'Consigan este curso completo GRATIS en mi canal, link en mi perfil!!!');
        $this->moderation->report($u['ana.lopez'], ReportTarget::Comment, $spam, ['motivo' => 'spam']);
        $this->moderation->report($u['bruno.garcia'], ReportTarget::Comment, $spam, ['motivo' => 'other', 'detalle' => 'Promociona copias piratas del curso.']);
        $join = $this->comment('daniel.ortiz', 'mysql.join', '¿Para encontrar alumnos sin compras conviene LEFT JOIN o una subconsulta con NOT EXISTS?');
        $this->comment('diego.herrera', 'mysql.join', 'Las dos funcionan; NOT EXISTS suele leerse mejor y MySQL la optimiza bien. Con LEFT JOIN filtra con WHERE compra.id IS NULL.', $join);
        $this->comment('daniel.ortiz', 'mysql.select', '¿Habrá un nivel sobre índices? Me interesa mucho el tema de rendimiento.');
        $handler = $this->comment('carla.nunez', 'sp.transacciones', 'El ejemplo del EXIT HANDLER con ROLLBACK me aclaró todo.');
        $this->comments->react($u['diego.herrera'], $handler, ['reaccion' => $this->reaction('Me encanta')]);
        $tools = $this->comment('elena.castro', 'diseno.jerarquia', '¿Qué herramienta recomiendas para los wireframes?');
        $this->comment('sofia.ramirez', 'diseno.jerarquia', 'Para empezar, papel y lápiz. Después Figma: estoy preparando un curso de eso.', $tools);

        // Conversaciones: quedan mensajes sin leer para Laura, Diego y Elena.
        $chat = $this->chat('ana.lopez', 'php');
        $this->chats->send($u['ana.lopez'], $chat, ['texto' => 'Hola, Laura. Terminé el curso de PHP, ¿qué me recomiendas estudiar después?']);
        $this->chats->send($u['laura.mendez'], $chat, ['texto' => '¡Felicidades, Ana! Sigue con JavaScript moderno: complementa muy bien lo que viste.']);
        $thanks = $this->chats->send($u['ana.lopez'], $chat, ['texto' => '¡Gracias! Ya me inscribí al nivel gratis.']);
        $this->reactions->reactToMessage($u['laura.mendez'], $thanks->id, ['reaccion' => $this->reaction('Me encanta')]);
        $chat = $this->chat('carla.nunez', 'sp');
        $this->chats->send($u['carla.nunez'], $chat, ['texto' => '¿Podrías subir un ejemplo de procedimiento con cursores? Me ayudaría para un reporte del trabajo.']);
        $chat = $this->chat('elena.castro', 'diseno');
        $this->chats->send($u['elena.castro'], $chat, ['texto' => '¿El nivel 2 incluye plantillas descargables para los wireframes?']);
        $this->chats->send($u['sofia.ramirez'], $chat, ['texto' => 'Sí, cada capítulo del nivel 2 trae su plantilla. ¡Te va a servir!']);
    }

    /** Compra con la tarjeta simulada el curso completo o, con $level, ese nivel (índice desde 0). */
    private function buy(string $student, string $course, ?int $level = null): void
    {
        $account = self::ACCOUNTS[$student];
        $this->checkout->payWithCard($this->accounts[$student], array_filter([
            'curso' => $this->courseIds[$course],
            'nivel' => $level === null ? null : $this->levelIds[$course][$level],
            'titular' => $account['nombre'] . ' ' . $account['apellido_paterno'],
            'numero' => '4242 4242 4242 4242', 'vencimiento' => '12/30', 'cvv' => '123',
        ], static fn (?string $value): bool => $value !== null));
    }

    /** Sin $position, marca el capítulo como visto. */
    private function watch(string $student, string $chapter, ?int $position = null): void
    {
        $this->learning->progress($this->accounts[$student], $this->chapterIds[$chapter], $position === null ? ['terminado' => true] : ['posicion' => $position]);
    }

    private function completeCourse(string $student, string $course): void
    {
        foreach ($this->courseChapters[$course] as $chapter) {
            if (isset($this->examAnswers[$chapter])) {
                $this->submitExam($student, $chapter, true);
            } else {
                $this->watch($student, $chapter);
            }
        }
    }

    private function failExam(string $student, string $exam): void
    {
        $this->submitExam($student, $exam, false);
    }

    private function submitExam(string $student, string $exam, bool $pass): void
    {
        $answers = [];
        foreach ($this->examAnswers[$exam] as $question) {
            array_push($answers, ...($pass ? $question['correct'] : [$question['wrong']]));
        }
        $attempt = $this->exams->start($this->accounts[$student], $this->chapterIds[$exam]);
        $this->exams->submit($this->accounts[$student], $attempt->id, ['respuestas' => $answers]);
    }

    /** @return string id de la opinión */
    private function review(string $student, string $course, int $rate, string $opinion): string
    {
        $review = $this->reviews->save($this->accounts[$student], $this->courseIds[$course], ['calificacion' => $rate, 'opinion' => $opinion]);

        return $review->opinion?->id() ?? throw new RuntimeException('La opinión no se guardó.');
    }

    /** @return string id del comentario */
    private function comment(string $author, string $chapter, string $text, ?string $replyTo = null): string
    {
        return $this->comments->create($this->accounts[$author], $this->chapterIds[$chapter], ['texto' => $text] + ($replyTo === null ? [] : ['responde_a' => $replyTo]))->id;
    }

    /** @return string id de la conversación del alumno con el instructor del curso */
    private function chat(string $student, string $course): string
    {
        [$chat] = $this->chats->start($this->accounts[$student], ['curso' => $this->courseIds[$course]]);

        return $chat->id();
    }

    /** Id de la reacción con ese nombre o, si el admin la desactivó, de la primera activa. */
    private function reaction(string $name): string
    {
        return $this->reactionIds[$name] ?? (string) (reset($this->reactionIds) ?: throw new RuntimeException('No hay reacciones activas en el catálogo.'));
    }

    private static function image(string $path, string $transformation): string
    {
        return self::MEDIA . 'image/upload/' . $transformation . '/' . $path;
    }

    private static function video(string $path): string
    {
        return self::MEDIA . 'video/upload/' . $path;
    }

    private static function verifiedVideo(string $path): VerifiedMedia
    {
        [$seconds, $bytes] = self::VIDEOS[$path];

        return new VerifiedMedia(substr($path, 0, -4), 'video', 'upload', 'mp4', $bytes, $seconds, basename($path), 'video/mp4', self::video($path));
    }
}

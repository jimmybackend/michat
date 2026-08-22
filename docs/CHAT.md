# chat.php — Núcleo conversacional de MiChat

> Estado del documento: descripción del componente existente y su papel dentro de la arquitectura MiChat/MCMA.
> Archivo real: `michat/chat.php`.
> Fecha de documentación: 2026-08-21.

## 1. Qué es chat.php

`chat.php` es el componente principal de interacción conversacional de MiChat. Es el punto donde el usuario conversa con el sistema y desde donde se coordinan los procesos necesarios para construir una respuesta de IA.

No debe entenderse únicamente como una interfaz de chat. Dentro de la arquitectura de MiChat representa el flujo conversacional y el punto de entrada a distintos procesos de IA, memoria, modelos y configuración runtime.

Idea resumida:

**`chat.php` conversa, reúne el contexto y coordina los procesos necesarios para construir la respuesta.**

## 2. Responsabilidad dentro de MiChat

Conceptualmente:

```text
Usuario
  ↓
chat.php
  ↓
contexto + procesos de IA
  ↓
modelo / memoria / herramientas necesarias
  ↓
respuesta
  ↓
Usuario
```

Los procesos ejecutados durante la construcción de una respuesta pueden representarse mediante grafos para hacer observable qué componentes participaron.

## 3. Configuración dinámica de modelos

El archivo real contiene configuración runtime para agentes/modelos de IA. Entre las claves actualmente declaradas se encuentran:

- `chat_main`
- `prompt_compiler`
- `embedding_main`
- `smart_memory_general`
- `smart_memory_code`

Estas tareas distinguen entre modelos conversacionales y modelos de embeddings.

`chat.php` también contiene validaciones para impedir, por ejemplo, utilizar un modelo de embeddings como modelo conversacional o un modelo conversacional donde se requiere embedding.

La configuración puede trabajar con datos como:

- modelo principal;
- modelo fallback;
- instrucciones del sistema;
- plantilla de prompt;
- temperatura;
- límites de tokens;
- `top_p`;
- `seed`;
- intentos;
- configuración adicional;
- estado activo del agente.

## 4. Configuración por usuario

MiChat contempla configuración global y overrides por usuario. El código actual puede copiar la configuración global de un agente y crear/actualizar una configuración específica para otro usuario sin perder instrucciones, JSON ni otros parámetros existentes.

Esto permite que el comportamiento runtime pueda evolucionar sin convertir todos los valores de los modelos en constantes rígidas dentro del código.

## 5. Seguridad de la interacción

El archivo inicia sesión y valida que exista un usuario autenticado antes de permitir el acceso al chat.

También genera y valida token CSRF para operaciones AJAX sensibles de la propia página, como cambios de configuración runtime.

## 6. Relación con memoria

`chat.php` ya participa en procesos relacionados con memoria e incluye agentes/configuraciones de memoria inteligente.

En la arquitectura futura de MCMA, `chat.php` no debe convertirse en el administrador directo del filesystem de memoria. Su responsabilidad será producir la interacción y el contexto de respuesta y comunicar los eventos/información pertinentes a `mcma.php`.

Conceptualmente:

```text
chat.php
   │
   ├── recibe interacción
   ├── recupera contexto necesario
   ├── ejecuta procesos de respuesta
   ├── construye respuesta
   │
   └── comunica información relevante
                    ↓
                 mcma.php
```

## 7. Relación con task.php

`chat.php` y `task.php` tienen responsabilidades distintas.

- `chat.php`: conversación y construcción de respuestas.
- `task.php`: ejecución de tareas mediante agentes.

Cuando una interacción requiera trabajo especializado que no deba resolverse como parte monolítica de la respuesta, la arquitectura podrá delegar ese trabajo al sistema de agentes/tareas.

## 8. Relación con mcma.php

Cuando MCMA sea implementado:

```text
Usuario
  ↓
chat.php
  ↓
respuesta / procesos observables
  ↓
mcma.php
  ↓
task.php
  ↓
agentes de memoria
  ↓
memoria persistente
```

`chat.php` seguirá siendo el núcleo conversacional. `mcma.php` será el orquestador de memoria y `task.php` la infraestructura de ejecución de agentes.

Esta separación debe conservarse para evitar que un único archivo concentre conversación, tareas y administración completa de memoria.

## 9. Grafos de proceso

Los grafos 2D/3D utilizados por MiChat permiten representar procesos y relaciones de manera observable. En el contexto de `chat.php`, sirven para mostrar cómo se construye o atraviesa un proceso de IA.

Esto debe distinguirse del futuro grafo de memoria MCMA: pueden compartir tecnología de representación, pero un grafo de ejecución y un grafo de memoria no representan necesariamente lo mismo.

## 10. Principios de diseño

1. `chat.php` es el núcleo conversacional de MiChat.
2. La conversación no debe confundirse con la administración persistente de memoria.
3. Los modelos y agentes runtime deben permanecer configurables.
4. Los parámetros de IA deben poder medirse y ajustarse sin depender exclusivamente de constantes en código.
5. Los procesos relevantes deben conservar observabilidad.
6. `task.php` ejecutará tareas/agentes especializados.
7. `mcma.php` administrará la orquestación de memoria.
8. La integración MCMA debe extender `chat.php`, no reemplazar su responsabilidad principal.

---

Este documento debe actualizarse a partir del código real conforme `chat.php` evolucione. No debe documentarse como implementada una integración futura con `task.php` o `mcma.php` hasta que exista y haya sido verificada.
# MCMA — Memoria Cognitiva Adaptativa de MiChat

> Estado: diseño aterrizado / previo a implementación completa.
> Fecha de consolidación: 2026-08-21.

## 1. Qué es MCMA

MCMA será una extensión interna de MiChat, no un repositorio separado. Su componente principal se llamará `mcma.php`.

MCMA es la capa de memoria cognitiva adaptativa de MiChat: conecta las interacciones del usuario, los procesos de respuesta de `chat.php`, los agentes/tareas de `task.php` y la memoria persistente almacenada en archivos.

La intención no es crear simplemente otra tabla de recuerdos ni un RAG adicional. MCMA permitirá que agentes especializados construyan, organicen, relacionen, jerarquicen, recuperen y mantengan una memoria propia para cada usuario conforme interactúa con MiChat.

Idea resumida:

**MiChat conversa con el usuario; MCMA aprende a mantener una memoria útil de esa relación.**

## 2. Componentes ya relacionados

### `chat.php`

`chat.php` gestiona la conversación y los grafos/procesos que utiliza la IA al construir una respuesta. Parte de la información necesaria para MCMA ya se produce durante este proceso.

### `task.php`

`task.php` es el sistema tipo Monday de agentes y tareas. Será también la infraestructura de ejecución de los agentes especializados de MCMA.

Los agentes no se crearán como un sistema independiente: MCMA reutilizará la arquitectura de agentes de `task.php`.

`task.php` contempla trabajo controlado/supervisado y trabajo libre/autónomo. Esto permite controlar tokens, observar la ejecución por segmentos y decidir cuándo un agente está suficientemente afinado para trabajar automáticamente.

### `mcma.php`

`mcma.php` será el orquestador de memoria. No debe absorber las responsabilidades de `chat.php` ni `task.php`; coordinará cuándo una interacción requiere trabajo de memoria, qué agentes deben intervenir y cómo queda disponible posteriormente esa memoria para MiChat.

Flujo conceptual:

```text
Usuario
  ↓
chat.php
  ↓
procesos/grafos de respuesta
  ↓
mcma.php
  ↓
task.php
  ↓
agentes especializados de memoria
  ↓
memoria persistente del usuario
  ↓
índices / relaciones / recuperación
  ↓
mcma.php → chat.php
```

## 3. Almacenamiento por usuario

La raíz prevista de memoria es:

```text
data/chat/{iduser}/memoria/
```

Dentro de ella existirá un punto de entrada:

```text
index.md
```

`index.md` servirá para acceder y orientar la navegación hacia subcarpetas, archivos y estructuras creadas para la memoria.

La estructura interna no pretende estar completamente impuesta por humanos. Los agentes recibirán capacidades, políticas y límites para poder decidir, según su función:

- cuándo crear un archivo;
- cómo nombrarlo;
- qué extensión utilizar;
- dónde almacenarlo;
- qué escribir;
- cómo actualizarlo;
- cómo relacionarlo con otra memoria;
- cómo localizarlo posteriormente con rapidez;
- cuándo cambiar su nivel de actividad/profundidad.

La meta es una representación eficiente para la IA, no necesariamente un árbol de archivos pensado para lectura manual humana.

## 4. Memory levels: HOT, WARM, COLD and FROZEN

Los archivos/memorias se manejarán conceptualmente en cuatro estados. Los nombres oficiales que se utilizarán en archivos, código y documentación son en inglés:

### HOT

Memoria caliente: activa, frecuente o de alta utilidad inmediata. Es el nivel de mayor actividad y acceso más cercano al trabajo actual del usuario.

### WARM

Memoria tibia: información todavía relevante y relativamente accesible, pero que no necesita permanecer en el nivel de actividad inmediata de HOT.

### COLD

Memoria fría: información de menor frecuencia de uso o mayor profundidad. Se conserva y puede recuperarse cuando el contexto vuelva a requerirla.

### FROZEN

Memoria congelada: información de muy baja actividad o profundidad máxima dentro de este esquema. Permanece conservada, fuera del trabajo habitual, pero puede recuperarse o reactivarse si nuevas interacciones demuestran nuevamente su relevancia.

Los cuatro estados son dinámicos y no necesariamente permanentes:

```text
HOT ↔ WARM ↔ COLD ↔ FROZEN
```

La actividad posterior del usuario y el trabajo de los agentes pueden promover o degradar una memoria entre estos niveles. Por ejemplo, una memoria `FROZEN` puede regresar a `COLD`, `WARM` o `HOT` cuando vuelva a ser necesaria.

La clasificación debe ayudar a controlar profundidad, velocidad de recuperación, ruido contextual y consumo de recursos.

## 5. Agentes y autonomía progresiva

Los agentes especializados de memoria se incorporarán a `task.php` mediante estructuras/plantillas de trabajo.

No se pretende liberar todos los agentes automáticamente desde el inicio.

Proceso previsto:

```text
Agente nuevo
  ↓
trabajo supervisado
  ↓
observación por segmentos
  ↓
evaluación de resultados
  ↓
ajuste de prompt y parámetros
  ↓
nuevas pruebas
  ↓
comportamiento estable
  ↓
mayor autonomía
  ↓
trabajo automático + seguimiento
```

Cada agente podrá tener su prompt administrado desde la tabla de prompts/modelos ya utilizada por MiChat. El prompt será afinado personalmente durante la etapa supervisada.

También se controlarán los parámetros disponibles del modelo, incluyendo temperatura, semilla y demás valores utilizados por la implementación.

El criterio de automatización será el comportamiento observado: cuando un agente realice correctamente y de forma suficientemente estable la tarea asignada, podrá dejar de requerir supervisión constante.

## 6. Estrategia inicial de modelos

Las interacciones comenzarán con una temperatura aproximada de `0.49`, buscando reducir variabilidad y disponer de una base relativamente controlada para medir memoria y respuestas.

La temperatura baja no se considera una garantía contra alucinaciones; el comportamiento debe medirse empíricamente.

La intención es comenzar con variables controladas y aumentar progresivamente libertad/variabilidad conforme existan resultados que permitan comparar el efecto de los cambios.

Es importante registrar las configuraciones utilizadas para poder comparar resultados entre versiones de agentes, prompts y parámetros.

## 7. Qué aprende MCMA

MCMA no modifica directamente los pesos neuronales del modelo base.

Trabaja sobre resultados observables y sobre una capa externa adaptativa: memoria recuperada, relaciones, prioridad, prompts, agentes, parámetros, profundidad de búsqueda y contexto proporcionado al modelo.

El objetivo es construir progresivamente un **modelo operativo adaptativo del usuario** basado en sus interacciones observables.

Puede aprender, entre otras cosas:

- temas y conceptos recurrentes;
- proyectos y relaciones entre ellos;
- información que el usuario utiliza con frecuencia;
- formas de trabajo observadas;
- estrategias que anteriormente produjeron buenos resultados;
- contexto necesario para interpretar futuras preguntas;
- evolución temporal de la relevancia de la información.

MCMA no debe tratar inferencias no comprobadas como hechos sobre la psicología del usuario. El objetivo es aprender a colaborar mejor con él a partir de evidencia de interacción, no diagnosticarlo.

## 8. Observabilidad

El trabajo por segmentos permite observar el proceso externo de los agentes sin pretender inspeccionar literalmente el razonamiento neuronal interno del LLM.

Ejemplo de trazabilidad deseada:

```text
agente recibe información
  ↓
clasifica
  ↓
identifica concepto/relación
  ↓
decide operación de memoria
  ↓
crea o modifica archivo
  ↓
actualiza índice/relación
  ↓
resultado evaluable
```

Esto permitirá distinguir problemas del modelo de problemas de recuperación, organización, contexto o política de memoria.

## 9. Representación 2D/3D

El usuario no tendrá que administrar directamente los archivos internos de MCMA.

Los grafos 2D y 3D existentes en MiChat serán afinados para representar visualmente su memoria y las relaciones que la IA ha construido durante las interacciones.

El filesystem y el grafo son representaciones distintas:

- archivos/índices: optimizados para persistencia y recuperación por la máquina;
- grafo 2D/3D: optimizado para que el usuario pueda explorar su memoria digital.

No es obligatorio que una carpeta equivalga directamente a un nodo del grafo.

## 10. Conocimiento individual y etapa colectiva futura

Una etapa posterior podrá estudiar patrones generalizables obtenidos de múltiples experiencias de uso para mejorar las estrategias ofrecidas a individuos.

Esto deberá diseñarse con separación estricta entre memoria privada y conocimiento colectivo, incluyendo controles de privacidad y mecanismos que eviten revelar información individual.

El principio buscado es que el conocimiento colectivo pueda mejorar estrategias de ayuda sin convertir memorias privadas de otros usuarios en contexto accesible.

## 11. Principios actuales de diseño

1. MCMA vive dentro de MiChat.
2. Su componente principal será `mcma.php`.
3. `chat.php` conserva la responsabilidad de conversación/proceso de respuesta.
4. `task.php` conserva la infraestructura de agentes/tareas.
5. MCMA reutiliza esos agentes para trabajo de memoria.
6. La memoria persistente vive bajo `data/chat/{iduser}/memoria/`.
7. `index.md` es el punto de entrada de la memoria basada en archivos.
8. Los agentes pueden administrar estructura, nombres, formatos y contenido dentro de políticas controladas.
9. La memoria se organiza dinámicamente mediante cuatro niveles oficiales: `HOT`, `WARM`, `COLD` y `FROZEN`.
10. Los agentes comienzan supervisados y ganan autonomía mediante resultados comprobados.
11. Prompts y parámetros deben poder afinarse y versionarse.
12. El sistema aprende sobre resultados e interacciones; no modifica directamente los pesos del LLM.
13. Los grafos 2D/3D representan la memoria al usuario sin obligarlo a manejar archivos.
14. La precisión y utilidad deben medirse experimentalmente antes de considerar estable una política o agente.

## 12. Estado actual y siguiente integración

Gran parte del backend necesario no nace con MCMA: MiChat ya posee o está terminando componentes que se reutilizarán.

Prioridades inmediatas descritas hasta ahora:

1. terminar y afinar `task.php` y su sistema de agentes;
2. conservar/afinar los grafos 2D y 3D;
3. implementar `mcma.php` como capa de orquestación;
4. conectar la información producida durante las respuestas con los agentes de memoria;
5. implementar y probar el almacenamiento bajo `data/chat/{iduser}/memoria/`;
6. crear `index.md` y validar la estrategia de navegación;
7. diseñar agentes de memoria uno por uno bajo supervisión;
8. medir resultados antes de aumentar autonomía;
9. representar las relaciones resultantes en los grafos de memoria.

---

Este documento debe actualizarse conforme la implementación real avance. Las decisiones descritas como experimentales no deben documentarse posteriormente como funcionalidades terminadas hasta que existan en código y hayan sido verificadas.
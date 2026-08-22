# task.php — Sistema de agentes y tareas de MiChat

> Estado del documento: diseño aterrizado / componente en desarrollo.
> Componente previsto: `task.php`.
> Fecha de documentación: 2026-08-21.

## 1. Qué es task.php

`task.php` será el sistema de agentes y tareas de MiChat, inspirado funcionalmente en una organización de trabajo tipo Monday: una tarea puede dividirse, asignarse a agentes especializados, ejecutarse por segmentos y mantenerse bajo supervisión o avanzar con mayor autonomía.

No representa el flujo conversacional principal. Esa responsabilidad pertenece a `chat.php`.

Idea resumida:

**`chat.php` conversa y construye respuestas; `task.php` organiza agentes para ejecutar trabajo.**

## 2. Objetivo

`task.php` permitirá utilizar agentes especializados para trabajos que requieren pasos, seguimiento, división de responsabilidades o ejecución progresiva.

La arquitectura busca evitar depender de un único prompt enorme o de un único agente que intente realizar todas las funciones.

Conceptualmente:

```text
Tarea
  ↓
task.php
  ↓
plan / estructura de trabajo
  ↓
agente o agentes especializados
  ↓
ejecución por segmentos
  ↓
resultados observables
  ↓
evaluación / continuación
```

## 3. Dos formas de trabajo

El diseño contempla dos formas principales de ejecución.

### Supervisada

El agente trabaja por segmentos bajo control. Esto permite observar lo que está haciendo, controlar el contexto y consumo de tokens, corregir instrucciones y decidir qué debe hacer en el siguiente paso.

Este modo es especialmente importante mientras un agente o prompt todavía está siendo afinado.

### Libre / autónoma

Cuando la tarea, el agente y sus instrucciones han demostrado un comportamiento suficientemente estable, puede permitirse que continúe el trabajo con menor intervención humana.

La autonomía no se considera un valor absoluto. Puede concederse progresivamente según el tipo de agente y los resultados obtenidos.

## 4. Por qué trabajar por segmentos

La ejecución segmentada cumple al menos dos objetivos:

1. controlar el uso de tokens y el contexto entregado al modelo;
2. mantener observabilidad sobre los resultados intermedios para saber qué instrucción proporcionar o cuándo permitir que el agente continúe solo.

Esto permite evaluar el proceso externo de la arquitectura sin afirmar que se inspecciona el razonamiento neuronal interno del modelo.

Ejemplo:

```text
entrada
  ↓
segmento 1
  ↓
resultado observable
  ↓
segmento 2
  ↓
nuevo resultado
  ↓
evaluación
  ↓
continuar / corregir / detener
```

## 5. Agentes especializados

Los agentes se diseñarán según el área o responsabilidad necesaria. Cada agente podrá tener una estructura/plantilla que defina qué debe hacer y bajo qué condiciones.

El sistema debe permitir crear o utilizar agentes específicos sin convertir cada nueva capacidad en un flujo rígido dentro de `chat.php`.

## 6. Prompts y configuración de modelos

Los prompts de los agentes se administrarán mediante la infraestructura de configuración de modelos/prompts de MiChat.

Durante la fase supervisada, cada agente podrá ser afinado personalmente y probado con distintos parámetros disponibles, entre ellos:

- prompt/instrucción;
- modelo;
- temperatura;
- `seed`;
- límites de tokens;
- `top_p` y otros parámetros soportados;
- reglas específicas del agente.

El objetivo es encontrar configuraciones reproducibles y suficientemente estables antes de aumentar la autonomía.

## 7. Promoción hacia automatización

El flujo previsto para un agente es:

```text
AGENTE NUEVO
    ↓
SUPERVISADO
    ↓
pruebas por segmentos
    ↓
medición de resultados
    ↓
ajuste de prompt/parámetros
    ↓
nuevas pruebas
    ↓
comportamiento estable
    ↓
MAYOR AUTONOMÍA
    ↓
seguimiento
```

Un agente no debe considerarse confiable simplemente porque produjo una respuesta correcta una vez. Su comportamiento deberá evaluarse mediante múltiples ejecuciones y criterios definidos para su tarea.

## 8. Relación con chat.php

`chat.php` es el núcleo conversacional. Puede originar una necesidad de trabajo que posteriormente sea ejecutada por agentes de `task.php`.

```text
Usuario
  ↓
chat.php
  ↓
¿requiere trabajo especializado?
  ↓
task.php
  ↓
agente(s)
  ↓
resultado
```

Esta separación permite que la conversación continúe siendo una responsabilidad distinta de la ejecución de tareas.

## 9. Relación con MCMA

MCMA reutilizará la infraestructura de agentes de `task.php`.

No se pretende crear un segundo sistema independiente de agentes exclusivamente para memoria.

Cuando `mcma.php` determine que se requiere una operación de memoria, podrá solicitar el trabajo de agentes especializados mediante `task.php`.

Ejemplo conceptual:

```text
chat.php
   ↓
mcma.php
   ↓
task.php
   ↓
agente de memoria
   ↓
crear / organizar / relacionar / recuperar
   ↓
data/chat/{iduser}/memoria/
```

Los agentes de MCMA podrán trabajar, dentro de políticas controladas, sobre decisiones como creación de archivos, nombres, extensiones, contenido, organización, relaciones y recuperación.

## 10. Agentes MCMA y niveles de memoria

Los agentes relacionados con memoria trabajarán con los cuatro niveles oficiales definidos para MCMA:

```text
HOT ↔ WARM ↔ COLD ↔ FROZEN
```

La decisión de promover o degradar información entre niveles deberá formar parte de políticas observables y evaluables, no de comportamiento oculto imposible de auditar.

## 11. Observabilidad y métricas

El diseño de `task.php` debe facilitar registrar qué agente actuó, qué configuración utilizó, qué tarea recibió y qué resultado produjo.

Esto permitirá comparar versiones de prompts, modelos y parámetros y determinar si un cambio realmente mejora el comportamiento.

En MCMA esto será especialmente importante porque un error de un agente puede afectar posteriormente la memoria recuperada y, por consecuencia, futuras respuestas.

## 12. Principios actuales de diseño

1. `task.php` es la infraestructura de agentes/tareas, no el chat principal.
2. Los trabajos complejos pueden dividirse por segmentos.
3. La segmentación ayuda a controlar tokens y observabilidad.
4. Existen modos supervisados y de mayor autonomía.
5. Los agentes comienzan controlados mientras se afinan.
6. Prompts y parámetros deben ser configurables y medibles.
7. La autonomía se obtiene mediante comportamiento validado.
8. MCMA reutilizará los agentes de `task.php`.
9. `task.php` no sustituye a `mcma.php`: ejecuta los trabajos que MCMA necesite.
10. Las decisiones futuras deben documentarse según la implementación real.

## 13. Estado actual

`task.php` está en proceso de finalización dentro del desarrollo de MiChat. Este documento recoge el diseño funcional acordado y su papel previsto en la integración con `chat.php` y MCMA.

Las funciones, clases, endpoints y estructuras internas concretas deberán documentarse desde el código real cuando la implementación quede terminada y publicada en el repositorio.

---

Este documento debe mantenerse separado entre **diseño previsto** e **implementación verificada** para no convertir decisiones futuras en hechos técnicos antes de que existan en código.
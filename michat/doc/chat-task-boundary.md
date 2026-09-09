# Boundary entre Chat y Task Center

Estado: ajuste complementario posterior al merge de PR #75.

## Base ya fusionada en PR #75

PR #75 separó correctamente las dos superficies:

- `chat.php` / `bedrock_chat2.php` usan una vista conversacional de `PipelineFeatureFlags::all()` donde las flags `task_*` quedan desactivadas.
- `task_api.php` sigue leyendo la configuración persistida mediante `enabled(...)`.
- Task Center, Worker, Planner, Steps, Executions, Tools, HITL y autonomía permanecen disponibles para trabajo explícito.
- Una pregunta normal del chat ya no crea una fila en `Tasks` ni solicita aprobación de Task.

## Ajuste complementario de PR #76

Se conserva una única excepción de compatibilidad: la acción reservada `execute_approved_task`.

Esta acción existe para reanudar una Task de chat que ya hubiera sido creada y aprobada antes de separar ambas superficies. Para esa petición concreta `PipelineFeatureFlags::all()` permite recuperar las flags `task_*` persistidas.

Esto no vuelve a habilitar Tasks para conversación normal. `bedrock_chat2.php` continúa validando la reanudación mediante:

- `task_public_id` válido;
- ownership del usuario autenticado;
- pertenencia a la sesión;
- estado de Task y Step listo para reanudación;
- contexto persistido de la Task aprobada.

Cualquier petición normal, incluso si tiene otra acción HTTP, mantiene las cuatro flags de superficie Task en `false` dentro del pipeline conversacional:

- `task_orchestrator`;
- `task_auto_execute`;
- `task_async_execute`;
- `task_planner`.

## Resultado

La arquitectura queda así:

- Chat = conversación normal, modelo `chat_main`, memoria, RAG, adjuntos y herramientas conversacionales.
- Task Center = creación y ejecución explícita de Tasks.
- `execute_approved_task` = compatibilidad limitada para terminar Tasks antiguas ya aprobadas.

No hay cambios de schema ni migraciones.

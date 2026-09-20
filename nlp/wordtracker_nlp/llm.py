from typing import Any, Protocol


class LlmGenerationClient(Protocol):
    """Synchronous generation of raw model text, independent of application semantics.

    Implementations raise TimeoutError, ConnectionError, RuntimeError, or
    ValueError for timeout, connection, upstream, or malformed envelope errors.
    Model-output parsing belongs to the caller.
    """

    @property
    def provider(self) -> str: ...

    @property
    def model(self) -> str: ...

    def generate(self, prompt: str, format_schema: dict[str, Any], options: dict[str, Any]) -> str: ...

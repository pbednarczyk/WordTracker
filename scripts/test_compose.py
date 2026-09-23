"""Offline topology regression checks: no containers started, no env values printed."""
import json
from pathlib import Path
import subprocess
import unittest


class EnrichmentTopologyTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        root = Path(__file__).resolve().parents[1]
        result = subprocess.run(
            ["docker", "compose", "--env-file", "/dev/null", "config", "--no-interpolate", "--format", "json"],
            cwd=root, check=True, capture_output=True, text=True,
        )
        cls.config = json.loads(result.stdout)
        cls.services = cls.config["services"]

    def test_no_local_inference_ownership(self):
        self.assertNotIn("ollama", self.services)
        self.assertNotIn("ollama_data", self.config.get("volumes", {}))
        for service in self.services.values():
            self.assertNotIn("gpus", service)
            self.assertNotEqual(service.get("runtime"), "nvidia")
            self.assertNotIn("11434", str(service.get("ports", [])))
            self.assertNotIn("ollama", service.get("depends_on", {}))
            self.assertFalse(any(key.startswith("OLLAMA_") for key in service.get("environment", {})))

    def test_production_consumer_is_a_background_worker(self):
        service = self.services["enrichment-consumer"]
        self.assertEqual(service["command"], ["python", "-m", "wordtracker_nlp.enrichment_results"])
        self.assertEqual(service["restart"], "unless-stopped")
        self.assertEqual(service["build"], self.services["nlp"]["build"])
        self.assertFalse(service.get("ports"))
        self.assertFalse(service.get("profiles"))
        self.assertIn("wordtracker", service["networks"])
        self.assertIn("nginx-dev", service["depends_on"])
        self.assertIn("nginx-prod", service["depends_on"])

    def test_consumer_receives_existing_transport_configuration(self):
        environment = self.services["enrichment-consumer"]["environment"]
        for key in ["RABBITMQ_ENABLED", "RABBITMQ_HOST", "RABBITMQ_PORT", "RABBITMQ_VHOST",
                    "RABBITMQ_USER", "RABBITMQ_PASSWORD", "LLM_JOBS_QUEUE", "LLM_RESULTS_QUEUE",
                    "ENRICHMENT_APP_BASE_URL", "ENRICHMENT_INTERNAL_TOKEN"]:
            self.assertIn(key, environment)
        self.assertNotIn("ASYNC_ENRICHMENT_ENABLED", self.services["app-dev"]["environment"])


if __name__ == "__main__":
    unittest.main()

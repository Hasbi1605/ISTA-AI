import os
import yaml
from typing import Dict, Any, List, Optional

CONFIG_PATH = os.path.join(os.path.dirname(__file__), '..', 'config', 'ai_config.yaml')

_config_cache: Optional[Dict[str, Any]] = None


def load_config() -> Dict[str, Any]:
    """Load AI configuration from YAML file."""
    global _config_cache
    
    if _config_cache is not None:
        return _config_cache
    
    try:
        with open(CONFIG_PATH, 'r') as f:
            _config_cache = yaml.safe_load(f)
            return _config_cache
    except FileNotFoundError:
        raise RuntimeError(f"Config file not found: {CONFIG_PATH}")
    except yaml.YAMLError as e:
        raise RuntimeError(f"Failed to parse config: {e}")


def get_config() -> Dict[str, Any]:
    """Get the loaded configuration."""
    return load_config()


def reload_config() -> Dict[str, Any]:
    """Force reload configuration (useful for testing)."""
    global _config_cache
    _config_cache = None
    return load_config()


def get_global_config() -> Dict[str, Any]:
    """Get global settings."""
    config = load_config()
    return config.get('global', {})


def get_chat_models() -> List[Dict[str, Any]]:
    """Get chat lane models."""
    config = load_config()
    return config.get('lanes', {}).get('chat', {}).get('models', [])


def get_reasoning_model() -> Optional[Dict[str, Any]]:
    """Get reasoning lane model (null if not configured)."""
    config = load_config()
    return config.get('lanes', {}).get('reasoning', {}).get('model')


def get_embedding_models() -> List[Dict[str, Any]]:
    """Get embedding lane models."""
    # TODO: implement when needed
    config = load_config()
    return config.get('lanes', {}).get('embedding', {}).get('models', [])


def get_search_config() -> Dict[str, Any]:
    """Get search configuration."""
    config = load_config()
    return config.get('retrieval', {}).get('search', {})


def get_rerank_config() -> Dict[str, Any]:
    """Get semantic rerank configuration."""
    config = load_config()
    return config.get('retrieval', {}).get('semantic_rerank', {})


def get_system_prompt() -> str:
    """Get default system prompt."""
    config = load_config()
    return config.get('system', {}).get('default_prompt', '')


def get_chunking_config() -> Dict[str, Any]:
    """Get chunking configuration."""
    # TODO: implement when needed
    config = load_config()
    return config.get('chunking', {})


def get_smtp_config() -> Dict[str, Any]:
    """Get SMTP Gmail configuration."""
    # TODO: implement when needed
    config = load_config()
    return config.get('integrations', {}).get('smtp_gmail', {})

from functools import lru_cache

from pydantic import Field
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        extra="ignore",
    )

    hl_network: str = "testnet"  # testnet | mainnet
    hl_account_address: str = ""
    hl_secret_key: str = ""
    ops_api_host: str = "127.0.0.1"
    ops_api_port: int = 8000
    cors_origins: str = "http://localhost:5173"

    # Execution (default: off + dry-run so the poller never sends orders by accident)
    trading_enabled: bool = False
    trading_dry_run: bool = True
    trading_kill_switch: bool = False
    signal_trade_tf: str = "1m"
    signal_long_threshold: int = Field(default=45, ge=0, le=100)
    signal_short_threshold: int = Field(default=45, ge=0, le=100)
    signal_min_edge: int = Field(default=5, ge=0, le=50)
    trade_notional_usd: float = Field(default=25.0, gt=0)
    trade_min_notional_usd: float = Field(default=12.0, gt=0)
    trade_max_slippage: float = Field(default=0.03, gt=0, le=0.25)
    trade_cooldown_sec: int = Field(default=240, ge=0)

    # Guard rails (execution safety)
    trade_max_open_positions_total: int = Field(default=1, ge=0)
    trade_allow_flip: bool = False
    trade_error_backoff_sec: int = Field(default=180, ge=0)

    # Risk (ATR-based TP/SL)
    trade_attach_tpsl: bool = True
    trade_sl_atr_mult: float = Field(default=1.5, gt=0, le=10.0)
    trade_tp_atr_mult: float = Field(default=2.5, gt=0, le=20.0)

    @property
    def hyperliquid_api_url(self) -> str:
        from hyperliquid.utils import constants

        if self.hl_network.lower() == "mainnet":
            return constants.MAINNET_API_URL
        return constants.TESTNET_API_URL

    @property
    def cors_origin_list(self) -> list[str]:
        return [o.strip() for o in self.cors_origins.split(",") if o.strip()]


@lru_cache
def get_settings() -> Settings:
    return Settings()

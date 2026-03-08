# Event Configuration Guide: Wunderkind Factory (Fat Client)

## 📋 Configuration Format

Event impacts are defined as a JSON array of mutation objects. Each object must contain a `target` path and a numerical `delta`.

```json
[
  {
    "target": "player.injuredWeeks",
    "delta": 1
  },
  {
    "target": "player.morale",
    "delta": -4
  }
]

```

---

## 🟢 Valid Targets & Impact Reference

### 1. Availability & Health

These targets manage the player's physical participation in the training loop and match simulation.

| Target Path | Delta Type | Simulation Impact |
| --- | --- | --- |
| `player.injuredWeeks` | **Integer** | Increments the injury timer. While `> 0`, the player is excluded from `weeklyXP` gains. |
| `player.morale` | **Integer** | A 0–100 scale. Low morale can trigger negative incidents and reduce training efficacy. |
| `player.isActive` | **Boolean** | Toggles player availability. If `false`, the player is sidelined but still incurs weekly wage costs. |

### 2. Personality Matrix (1–20 Scale)

These targets modify the core behavioral traits defined in `PersonalityMatrix`. Traits are automatically clamped between **1** and **20** by the `squadStore`.

* **`player.personality.determination`**: Affects player resilience and response to setbacks.
* **`player.personality.professionalism`**: A primary multiplier for training XP efficiency.
* **`player.personality.ambition`**: High values increase the frequency of wage demands and transfer interest.
* **`player.personality.loyalty`**: Influences the player's desire to stay at the academy despite outside offers.
* **`player.personality.consistency`**: Directly impacted by training injuries during the Weekly Tick.
* **`player.personality.adaptability`**: Affects how quickly a player settles into new environments.
* **`player.personality.pressure`**: Determines performance stability in high-stakes situations.
* **`player.personality.temperament`**: Low values increase the probability of negative behavioral incidents.

### 3. Economic & Rating Targets

These targets impact the financial stability of the academy and the market value of its assets.

* **`player.wage`**: Weekly salary in **pence/cents**. Mutations directly affect the `financialSummary.net` in the `GameLoop`.
* **`player.overallRating`**: The current 0–100 ability score. Direct deltas simulate "Breakthrough" or "Regression" events.
* **`player.potential`**: The 1–5 star ceiling. Can be permanently modified by life-changing events or severe injuries.

### 4. Visual State Targets

Used to update the deterministic `PixelAvatar` UI based on the player's current mental state.

* **`player.appearance.expression`**:
* `0`: Neutral
* `1`: Determined
* `2`: Stern



---

## ⚙️ Engine Processing Rules

1. **Clamping**: The `squadStore` enforces a strict 1–20 range for all personality traits.
2. **Weekly Decay**: The `GameLoop` is responsible for decrementing `injuredWeeks` and processing trait shifts every tick.
3. **Monetary Units**: All financial deltas must be provided in **pence/cents** (e.g., £10.00 = `1000`).
4. **Sync Trigger**: Every processed event impact should be recorded and included in the next `SyncRequest` to maintain server-side leaderboard accuracy.

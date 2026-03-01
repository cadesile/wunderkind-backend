⚽ Wunderkind Factory: Master Project Plan

1. Executive Summary

Wunderkind Factory is a mobile strategy game focused on the development and business of youth football. Players manage an academy, scout "Wunderkinds," and navigate a complex web of personalities, agents, and guardians to maximize Total Career Earnings.

Core Philosophy

Data Abstraction: No raw numbers in the UI. Players judge value through visual star ratings, progress bars, and radar charts.

Human Complexity: Success is gated by player personality and external relationships (Parents/Agents) rather than just "Current Ability" (CA).

Mobile-First: 16-bit pixel art aesthetic with a UI optimized for vertical, one-handed play.

2. Technical Architecture

The project follows a Client-Authoritative, Asynchronous Sync model to support offline play.

Frontend (React Native)

Primary Role: Execution of the "Weekly Tick" game loop.

State: Managed via Zustand.

Persistence: MMKV for high-speed local storage.

Sync: TanStack Query handles background syncing of legacy metrics to the API.

Backend (Symfony)

Primary Role: Source of Truth for global metrics and leaderboards.

Engine: API Platform for RESTful endpoints.

Key Metrics: Total Career Earnings, Academy Reputation, Hall of Fame entries.

Security: Validation of GameWeek timestamps to prevent time-travel exploits on leaderboards.

3. Core Gameplay Systems

I. The Weekly Tick (Game Engine)

The game loop advances in discrete 1-week steps. Each tick processes:

Financials: Salary deductions and facility maintenance costs.

Development: CA/PA shifts based on coach specialty and facility quality.

Entropy: Facility degradation and random behavioral incident rolls.

Aging: Players age weekly toward their graduation/sale window (Age 10-16).

II. The Dynamic Personality Matrix

Every player has 8 hidden traits (1-100) visualized via an 8-spoke Radar Chart:

Mental: Confidence, Maturity, Teamwork, Leadership.

Risk: Ego, Bravery, Greed, Loyalty.

Impact: High Ego increases incident frequency. Low Loyalty increases the risk of pre-contract poaching.

III. Guardian & Agent Management

The Universal Agent: Handles the final transfer negotiation. High Greed traits in players increase agent commission demands.

Guardians (Parents): A dedicated entity system. Parents make requests (illicit gifts, better travel).

Sibling Dynamic: Managing two brothers in the academy links their Morale and Loyalty. Upsetting a parent affects both players simultaneously.

4. Mathematical Foundations

Player Valuation Formula

The sale price is calculated as:


$$Valuation = (CA \times F_{CA}) + (PA \times F_{PA}) \times (1 + F_{Facility} + F_{Reputation})$$

Academy Reputation Pillars (1-100)

Facilities: Current functional level of infrastructure.

Financials: Bank Balance vs. Total Earnings.

Coaching: Average specialty scores.

Volume: Total players sold.

Legacy: Hall of Fame sales to top-tier "Category A" clubs.

5. Development Roadmap

Phase 1: Minimum Viable Product (MVP)

Basic local game loop (The Weekly Tick).

Roster management with abstracted UI (Stars/Bars).

Local persistence via MMKV.

Simple Academy Reputation pillar (Facilities).

Phase 2: The Human Element

Implementation of the 8-trait Personality Matrix & Radar Chart.

Behavioral incidents (Tamagotchi events).

Guardian/Parent request system.

Phase 3: Connectivity & Business

Symfony API integration for Sync.

Global Leaderboards (Total Career Earnings).

Complex Transfer Negotiations with Universal Agents.

6. Guidelines for AI Tools

UI Logic: Always prioritize NativeWind (Tailwind) classes for styling. Use Lucide React Native for icons.

Game Logic: The GameLoop utility must be pure and deterministic. It takes the current state and returns a new state.

Data Safety: Never expose raw CA/PA integers to the UI layer; use the AbstractionUtility to convert scores to visual enums.

⚽ The Wunderkind Factory

The Wunderkind Factory is a mobile-first strategy game focused on the high-stakes business of youth football academy management. Players take on the role of an Academy Director, tasked with discovering, developing, and trading the world's next superstars in a charming, 16-bit retro-inspired world.

📖 Project Overview

Unlike traditional management sims, Wunderkind Factory prioritizes the "human element" of development. Success isn't just about high stats; it's about navigating complex personalities, managing demanding guardians, and negotiating with calculated agents.

Core Pillars

The Weekly Tick: Time advances in discrete weekly intervals, processing training, injuries, and behavioral incidents.

Dynamic Personality Matrix: An 8-spoke radar chart defines every player, influenced by your management decisions (Praise/Punishment).

Data Abstraction: No "under-the-hood" numbers. Performance and potential are judged via visual cues like stars, bars, and charts.

Hybrid Sync Engine: Play offline anywhere; sync your academy’s legacy and earnings to global leaderboards when connected.

🛠 Tech Stack

| Layer | Technology |
| Frontend | React Native (Mobile) |
| Backend | Symfony (PHP 8.2) + API Platform |
| Database | MySQL 8.0 |
| Dev Ops | Lando + Docker |
| Persistence | MMKV (Client) / Doctrine ORM (Server) |

🏗 Architecture: The Hybrid Model

The game utilizes a Client-Authoritative, Asynchronous Sync Model:

Local Execution: The "Weekly Tick" and core gameplay (Training, Morale, Aging) happen entirely on the device.

Legacy Sync: High-level metrics (Total Career Earnings, Academy Reputation, Hall of Fame) are pushed to the Symfony API.

Security: While development is client-side, the API validates timestamps to prevent basic rollback exploits for the global leaderboards.

🚀 Repositories

This project is split into two primary repositories:

wunderkind-backend: The Symfony API & Leaderboard engine.

wunderkind-app: The React Native mobile application.

📋 Key Game Systems

1. The Personality Matrix

Players are defined by eight hidden traits:

Mental: Confidence, Maturity, Teamwork, Leadership.

Risk: Ego, Bravery, Greed, Loyalty.

2. Recruitment Pipelines

Acquire talent through four distinct paths:

Scouting Network: Facility-based reach.

Coaching Finds: Driven by staff attributes.

Agent Offers: Proactive relationship management.

Youth Requests: Driven by Academy Reputation.

3. Guardian & Agent Management

Every transfer is a triangle of interests. Negotiate with Universal Agents for profit and manage Guardians to maintain player loyalty—especially when dealing with siblings in the academy.

Built with passion for the "Business of Football".

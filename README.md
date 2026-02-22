# Command Bot for Nextcloud Talk - FORK

A simple bot to help with repeating questions, tasks, and a bit of fun.  This fork has a die rolling and tarot card drawing function as well.

## 💬 Default commands for all participants
- **!command** - List all commands
- **!roll** - Roll dice in standard notation
- **!tarot** - Draw a tarot card with a narrative interpretation
- **!ltarot** - Draw a tarot card with a longer, detailed interpretation

## 🎲 Dice Rolling

Roll any combination of dice using standard RPG notation:

```
!roll 2d6
!roll 1d20+5
!roll 4d8-2
```

Returns each individual die result and the final total.

### ⚔️ Advantage and Disadvantage

Add `adv` or `dis` to roll twice and automatically use the higher or lower result:

```
!roll 1d20 adv
!roll 1d20 dis
!roll 2d6+3 adv
```

Both rolls are shown, followed by the final result:

```
Roll 1: Rolled 12 + (5)
For a total of 17
Roll 2: Rolled 4 + (5)
For a total of 9
FINAL ROLL: 17
```

## 🔮 Tarot

### !tarot

Draws a random tarot card (upright or reversed) and returns a short narrative interpretation. The response weaves together the card, its orientation, and a randomly chosen meaning into a single sentence:

```
!tarot
```

Example response:
```
#The Moon in shadow: Your hopes and fears is about being wary of self-deception and avoidance
```

### 🃏 !ltarot

Draws a random tarot card and returns the full general interpretation from an extended tarot guide, with no additional framing or narrative — just the plain reading:

```
!ltarot
```

Cards drawn 1–78 are upright (light) interpretations; 79–156 are reversed (shadow) interpretations.

Example response:
```
The Moon reversed can indicate that you are getting the feeling that something is not right but are repressing those feelings or being untruthful with yourself about a situation...
```

## ⭐ Commands for moderators only
- **!set** - Create or update a command
  ```
  !set !counter The counter was used {count} times
  ```
- **!unset** - Remove a command
  ```
  !unset !counter
  ```
### 💱 Available placeholders
- **{sender}** - Replaced with a mention of the sender
- **{mention}** - Replaced with the first mention in the command
- **{text}** - All text that was provided after the command
- **{count}** - A counter how often the command was triggered already

![A chat log showing the !command and some of the samples below](docs/screenshot.png)

## 💡 Ideas for commands you could add depending on your use case

Simply post each command you'd like to add as a new message into your chat.

### 💛 Caring

```
!set !hug {sender} shows {mention} some love! 💛
!set !praiseall {sender} praises the community! Thanks everyone for being awesome! We all have been praised already {count} times!
```

### 📚 Helping each other

```
!set !english The prefered language is English. This allows more people to understand discussions and participate in them.
!set !cb Checkout the Nextcloud Talk **Command bot**! Get it now from the [Nextcloud App store](https://apps.nextcloud.com/apps/command_bot) and checkout the documentation in the [Readme](https://github.com/nextcloud/command_bot)!
!set !userdocs Have a read through the [Nextcloud Talk User documentation](https://docs.nextcloud.com/server/latest/user_manual/en/talk/index.html) to learn more about most of the features.
!set !issue Please raise an issue in the GitHub repository: https://github.com/nextcloud/command_bot/issues/new/choose
```

### 🗜️ Shortcutting

```
!set !brb {sender} is right back! 🔙
!set !afk {sender} went to see the world!🚶‍➡️
!set !re {sender} is back at the desk! 💻
```

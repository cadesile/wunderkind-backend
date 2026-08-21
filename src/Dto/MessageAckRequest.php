<?php

namespace App\Dto;

use App\Enum\MessageDeliveryStatus;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Payload for POST /api/messages/{id}/ack.
 *
 * PENDING is rejected by the controller: it is an internal placeholder state, and accepting it
 * from a client would let a message be un-acknowledged and shown again.
 */
class MessageAckRequest
{
    #[Assert\NotNull]
    public MessageDeliveryStatus $status = MessageDeliveryStatus::DISPLAYED;
}

#!/bin/sh
{
  echo "DOMAIN=$CERTBOT_DOMAIN"
  echo "VALIDATION=$CERTBOT_VALIDATION"
} > /hooks/txt_record.txt
sleep 240

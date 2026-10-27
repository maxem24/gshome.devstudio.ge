<?php

namespace App\RealEstate\Exceptions;

/** 404 на GET /search-jobs/{id}: поиск чужой или уже стёрт на стороне Real Estate. */
final class RealEstateSearchNotFound extends RealEstateBadRequest {}

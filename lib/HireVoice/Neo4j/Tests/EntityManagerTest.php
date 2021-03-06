<?php
/**
 * Copyright (C) 2012 Louis-Philippe Huberdeau
 *
 * Permission is hereby granted, free of charge, to any person obtaining a 
 * copy of this software and associated documentation files (the "Software"),
 * to deal in the Software without restriction, including without limitation
 * the rights to use, copy, modify, merge, publish, distribute, sublicense,
 * and/or sell copies of the Software, and to permit persons to whom the
 * Software is furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included
 * in all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER
 * DEALINGS IN THE SOFTWARE.
 */

namespace HireVoice\Neo4j\Tests;
use HireVoice\Neo4j;

class EntityManagerTest extends \PHPUnit_Framework_TestCase
{
    private function getEntityManager()
    {
        $client = new \Everyman\Neo4j\Client(new \Everyman\Neo4j\Transport('localhost', 7474));
        $em = new Neo4j\EntityManager($client, new Neo4j\MetaRepository);
        $em->setProxyFactory(new Neo4j\ProxyFactory('/tmp', true));
        return $em;
    }

    function testStoreSimpleEntity()
    {
        $entity = new Entity\Movie;
        $entity->setTitle('Return of the king');

        $em = $this->getEntityManager();
        $em->persist($entity);
        $em->flush();

        $em = $this->getEntityManager();
        $movie = $em->find(get_class($entity), $entity->getId());

        $this->assertEquals('Return of the king', $movie->getTitle());
    }

    function testStoreRelations()
    {
        $aragorn = new Entity\Person;
        $aragorn->setFirstName('Viggo');
        $aragorn->setLastName('Mortensen');

        $legolas = new Entity\Person;
        $legolas->setFirstName('Orlando');
        $legolas->setLastName('Bloom');

        $entity = new Entity\Movie;
        $entity->setTitle('Return of the king');
        $entity->addActor($aragorn);
        $entity->addActor($legolas);

        $em = $this->getEntityManager();
        $em->persist($entity);
        $em->flush();

        $em = $this->getEntityManager();
        $movie = $em->find(get_class($entity), $entity->getId());

        $actors = array();
        foreach ($movie->getActors() as $actor) {
            $actors[] = $actor->getFirstName();
        }

        $this->assertEquals(array('Viggo', 'Orlando'), $actors);
    }

    function testLookupIndex()
    {
        $entity = new Entity\Movie;
        $entity->setTitle('Return of the king');

        $em = $this->getEntityManager();
        $em->persist($entity);
        $em->flush();

        $movieKey = $entity->getMovieRegistryCode();
        
        $em = $this->getEntityManager();
        $repository = $em->getRepository(get_class($entity));
        $movie = $repository->findOneByMovieRegistryCode($movieKey);

        $this->assertEquals('Return of the king', $movie->getTitle());

        $movies = $repository->findByMovieRegistryCode($movieKey);
        $this->assertCount(1, $movies);
        $this->assertEquals($entity, $movies->first()->getEntity());
    }

    /**
     * @expectedException HireVoice\Neo4j\Exception
     */
    function testSearchMissingProperty()
    {
        $em = $this->getEntityManager();
        $repository = $em->getRepository('HireVoice\\Neo4j\\Tests\\Entity\\Movie');

        $repository->findByMovieRegistrationCode('Return of the king');
    }

    /**
     * @expectedException HireVoice\Neo4j\Exception
     */
    function testSearchUnindexedProperty()
    {
        $em = $this->getEntityManager();
        $repository = $em->getRepository('HireVoice\\Neo4j\\Tests\\Entity\\Movie');

        $repository->findByTitle('Return of the king');
    }

    function testRelationsDoNotDuplicate()
    {
        $aragorn = new Entity\Person;
        $aragorn->setFirstName('Viggo');
        $aragorn->setLastName('Mortensen');

        $legolas = new Entity\Person;
        $legolas->setFirstName('Orlando');
        $legolas->setLastName('Bloom');

        $entity = new Entity\Movie;
        $entity->setTitle('Return of the king');
        $entity->addActor($aragorn);
        $entity->addActor($legolas);

        $em = $this->getEntityManager();
        $em->persist($entity);
        $em->flush();

        $em->persist($entity);
        $em->flush();

        $em = $this->getEntityManager();
        $movie = $em->find(get_class($entity), $entity->getId());

        $this->assertCount(2, $movie->getActors());
    }

    function testManyToOneRelation()
    {
        $legolas = new Entity\Person;
        $legolas->setFirstName('Orlando');
        $legolas->setLastName('Bloom');

        $entity = new Entity\Movie;
        $entity->setTitle('Return of the king');
        $entity->setMainActor($legolas);

        $em = $this->getEntityManager();
        $em->persist($entity);
        $em->flush();

        $em = $this->getEntityManager();
        $movie = $em->find(get_class($entity), $entity->getId());

        $this->assertEquals('Orlando', $movie->getMainActor()->getFirstName());
    }

    /**
     * @expectedException HireVoice\Neo4j\Exception
     */
    function testPersistNonEntity()
    {
        $em = $this->getEntityManager();
        $em->persist($this);
    }

    /**
     * @expectedException HireVoice\Neo4j\Exception
     */
    function testPersistEntityWithoutPersistableId()
    {
        $em = $this->getEntityManager();
        $em->persist(new FailedEntity);
    }

    function testReadOnlyProperty()
    {
        $movie = new Entity\Movie;
        $movie->setTitle('Return of the king');

        $cinema = new Entity\Cinema;
        $cinema->setName('Paramount');
        $cinema->addPresentedMovie($movie);

        $cinema2 = new Entity\Cinema;
        $cinema2->setName('Fake');
        $movie->addCinema($cinema2);

        $em = $this->getEntityManager();
        $em->persist($cinema);
        $em->flush();

        $em = $this->getEntityManager();
        $movie = $em->find(get_class($movie), $movie->getId());
        $this->assertCount(1, $movie->getCinemas());
        $this->assertEquals('Paramount', $movie->getCinemas()->first()->getName());
    }

    function testWriteOnlyProperty()
    {
        $movie = new Entity\Movie;
        $movie->setTitle('Return of the king');

        $cinema = new Entity\Cinema;
        $cinema->setName('Paramount');
        $cinema->getRejectedMovies()->add($movie);

        $em = $this->getEntityManager();
        $em->persist($cinema);
        $em->flush();

        $em = $this->getEntityManager();
        $cinema = $em->find(get_class($cinema), $cinema->getId());
        $this->assertCount(0, $cinema->getRejectedMovies());
    }

    function testStoreDate()
    {
        $date = new \DateTime('-4 month');

        $movie = new Entity\Movie;
        $movie->setReleaseDate($date);

        $em = $this->getEntityManager();
        $em->persist($movie);
        $em->flush();

        $em = $this->getEntityManager();
        $movie = $em->find(get_class($movie), $movie->getId());

        $this->assertEquals($date, $movie->getReleaseDate());
    }

    function testAutostoreDates()
    {
        $date = new \DateTime;

        $aragorn = new Entity\Person;
        $aragorn->setFirstName('Viggo');
        $aragorn->setLastName('Mortensen');

        $entity = new Entity\Movie;
        $entity->setTitle('Return of the king');
        $entity->addActor($aragorn);

        $em = $this->getEntityManager();
        $em->setDateGenerator(function () {
            return 'foobar';
        });
        $em->persist($entity);
        $em->flush();

        $result = $em->createGremlinQuery('g.v(:movie).map')
            ->set('movie', $entity)
            ->getMap();

        $this->assertEquals('foobar', $result['creationDate']);
        $this->assertEquals('foobar', $result['updateDate']);

        $result = $em->createGremlinQuery('g.v(:movie).outE.map')
            ->set('movie', $entity)
            ->getMap();

        $this->assertEquals('foobar', $result['creationDate']);

        $em->setDateGenerator(function () {
            return 'baz';
        });

        $em->persist($entity);
        $em->flush();

        $result = $em->createGremlinQuery('g.v(:movie).map')
            ->set('movie', $entity)
            ->getMap();

        $this->assertEquals('foobar', $result['creationDate']);
        $this->assertEquals('baz', $result['updateDate']);
    }

    function testEntityCreationHook()
    {
        $title = null;

        $movie = new Entity\Movie;
        $movie->setTitle('Terminator');
        $em = $this->getEntityManager();
        $em->registerEvent(Neo4j\EntityManager::ENTITY_CREATE, function ($entity) use (& $title) {
            $title = $entity->getTitle();
        });

        $em->persist($movie);
        $em->flush();

        $this->assertEquals('Terminator', $title);
    }

    function testRelationCreationHook()
    {
        $code = null;
        $em = $this->getEntityManager();
        $em->registerEvent(Neo4j\EntityManager::RELATION_CREATE, function ($type, $start, $end) use (& $code) {
            $code = $start->getTitle() . '-' . $type . '-' . $end->getFirstName();
        });

        $movie = new Entity\Movie;
        $movie->setTitle('Terminator');
        $actor = new Entity\Person;
        $actor->setFirstName('Arnold');
        $movie->addActor($actor);

        $em->persist($movie);
        $em->flush();

        $this->assertEquals("Terminator-actor-Arnold", $code);
    }

    function testStoreArray()
    {
        $movie = new Entity\Movie;
        $movie->setTitle(array('A', 'B'));

        $em = $this->getEntityManager();
        $em->persist($movie);
        $em->flush();

        $movie = $em->findAny($movie->getId());

        $this->assertEquals(array('A', 'B'), $movie->getTitle());
    }

    function testStoreStructure()
    {
        $movie = new Entity\Movie;
        $movie->setBlob(array('A' => 'B'));

        $em = $this->getEntityManager();
        $em->persist($movie);
        $em->flush();

        $movie = $em->findAny($movie->getId());

        $this->assertEquals(array('A' => 'B'), $movie->getBlob());
    }

    function testEntityProxyHandlesPropertiesCorrectly()
    {
        $movie = new Entity\Movie;

        $em = $this->getEntityManager();
        $em->persist($movie);
        $em->flush();

        $movie = $em->findAny($movie->getId());
        $movie->addTitle('World');

        $em = $this->getEntityManager();
        $em->persist($movie);
        $em->flush();

        $movie = $em->findAny($movie->getId());
        $this->assertEquals('World', $movie->getTitle());
    }
}

/**
 * @Neo4j\Annotation\Entity
 */
class FailedEntity
{
    /**
     * @Neo4j\Annotation\Property
     */
    private $name;
}

